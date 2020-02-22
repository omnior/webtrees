<?php

/**
 * webtrees: online genealogy
 * Copyright (C) 2019 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Fisharebest\Webtrees\Factories;

use Closure;
use Fisharebest\Webtrees\Contracts\FamilyFactoryInterface;
use Fisharebest\Webtrees\Contracts\GedcomRecordFactoryInterface;
use Fisharebest\Webtrees\Contracts\IndividualFactoryInterface;
use Fisharebest\Webtrees\Contracts\MediaFactoryInterface;
use Fisharebest\Webtrees\Contracts\NoteFactoryInterface;
use Fisharebest\Webtrees\Contracts\RepositoryFactoryInterface;
use Fisharebest\Webtrees\Contracts\SourceFactoryInterface;
use Fisharebest\Webtrees\Contracts\SubmitterFactoryInterface;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Media;
use Fisharebest\Webtrees\Note;
use Fisharebest\Webtrees\Repository;
use Fisharebest\Webtrees\Source;
use Fisharebest\Webtrees\Submitter;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use InvalidArgumentException;
use stdClass;

use function app;
use function assert;

/**
 * Make a GedcomRecord object.
 */
class GedcomRecordFactory extends AbstractGedcomRecordFactory implements GedcomRecordFactoryInterface
{
    /**
     * Create a GedcomRecord object.
     *
     * @param string      $xref
     * @param Tree        $tree
     * @param string|null $gedcom
     *
     * @return GedcomRecord|null
     */
    public function make(string $xref, Tree $tree, string $gedcom = null): ?GedcomRecord
    {
        // We do not know the type of the record.  Try them all in turn.
        return
            app(FamilyFactoryInterface::class)->make($xref, $tree, $gedcom) ??
            app(IndividualFactoryInterface::class)->make($xref, $tree, $gedcom) ??
            app(MediaFactoryInterface::class)->make($xref, $tree, $gedcom) ??
            app(NoteFactoryInterface::class)->make($xref, $tree, $gedcom) ??
            app(RepositoryFactoryInterface::class)->make($xref, $tree, $gedcom) ??
            app(SourceFactoryInterface::class)->make($xref, $tree, $gedcom) ??
            app(SubmitterFactoryInterface::class)->make($xref, $tree, $gedcom) ??
            $this->cache->remember(__CLASS__ . $xref . '@' . $tree->id(), function () use ($xref, $tree, $gedcom) {
                $gedcom = $gedcom ?? $this->gedcom($xref, $tree);

                $pending = $this->pendingChanges($tree)->get($xref);

                if ($gedcom === null && $pending === null) {
                    return null;
                }

                $xref = $this->extractXref($gedcom ?? $pending, $xref);
                $type = $this->extractType($gedcom ?? $pending);

                return $this->newGedcomRecord($type, $xref, $gedcom ?? '', $pending, $tree);
            });
    }

    /**
     * Create a GedcomRecord object from raw GEDCOM data.
     *
     * @param string      $xref
     * @param string      $gedcom  an empty string for new/pending records
     * @param string|null $pending null for a record with no pending edits,
     *                             empty string for records with pending deletions
     * @param Tree        $tree
     *
     * @return GedcomRecord
     */
    public function new(string $xref, string $gedcom, ?string $pending, Tree $tree): GedcomRecord
    {
        return new GedcomRecord($xref, $gedcom, $pending, $tree);
    }

    /**
     * Create a GedcomRecord object from a row in the database.
     *
     * @param Tree $tree
     *
     * @return Closure
     */
    public function mapper(Tree $tree): Closure
    {
        return function (stdClass $row) use ($tree): GedcomRecord {
            $record = $this->make($row->o_id, $tree, $row->o_gedcom);
            assert($record instanceof GedcomRecord);

            return $record;
        };
    }

    /**
     * @param string      $type
     * @param string      $xref
     * @param string      $gedcom
     * @param string|null $pending
     * @param Tree        $tree
     *
     * @return GedcomRecord|null
     */
    protected function newGedcomRecord(string $type, string $xref, string $gedcom, ?string $pending, Tree $tree): ?GedcomRecord
    {
        switch ($type) {
            case Family::RECORD_TYPE:
                return app(FamilyFactoryInterface::class)->new($xref, $gedcom, $pending, $tree);

            case Individual::RECORD_TYPE:
                return app(IndividualFactoryInterface::class)->new($xref, $gedcom, $pending, $tree);

            case Media::RECORD_TYPE:
                return app(MediaFactoryInterface::class)->new($xref, $gedcom, $pending, $tree);

            case Note::RECORD_TYPE:
                return app(NoteFactoryInterface::class)->new($xref, $gedcom, $pending, $tree);

            case Repository::RECORD_TYPE:
                return app(RepositoryFactoryInterface::class)->new($xref, $gedcom, $pending, $tree);

            case Source::RECORD_TYPE:
                return app(SourceFactoryInterface::class)->new($xref, $gedcom, $pending, $tree);

            case Submitter::RECORD_TYPE:
                return app(SubmitterFactoryInterface::class)->new($xref, $gedcom, $pending, $tree);

            default:
                return $this->new($xref, $gedcom, $pending, $tree);
        }
    }

    /**
     * Extract the type of a GEDCOM record
     *
     * @param string $gedcom
     *
     * @return string
     * @throws InvalidArgumentException
     */
    protected function extractType(string $gedcom): string
    {
        if (preg_match('/^0(?: @' . Gedcom::REGEX_XREF . '@)? ([_A-Z0-9]+)/', $gedcom, $match)) {
            return $match[1];
        }

        throw new InvalidArgumentException('Invalid GEDCOM record: ' . $gedcom);
    }

    /**
     * Fetch GEDCOM data from the database.
     *
     * @param string $xref
     * @param Tree   $tree
     *
     * @return string|null
     */
    protected function gedcom(string $xref, Tree $tree): ?string
    {
        return DB::table('other')
            ->where('o_id', '=', $xref)
            ->where('o_file', '=', $tree->id())
            ->whereNotIn('o_type', [
                Note::RECORD_TYPE,
                Repository::RECORD_TYPE,
                Submitter::RECORD_TYPE,
            ])
            ->value('o_gedcom');
    }
}
