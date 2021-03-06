<?php

namespace FKSDB\Components\Grids\Deduplicate;

use FKSDB\Components\Grids\BaseGrid;
use ModelPerson;
use Nette\Utils\Html;
use NiftyGrid\DataSource\NDataSource;
use ORM\Tables\TypedTableSelection;
use Persons\Deduplication\DuplicateFinder;

/**
 *
 * @author Michal Koutný <xm.koutny@gmail.com>
 */
class PersonsGrid extends BaseGrid {

    /**
     * @var TypedTableSelection
     */
    private $trunkPersons;

    /**
     * @var array trunkId => ModelPerson
     */
    private $pairs;

    function __construct(TypedTableSelection $trunkPersons, $pairs) {
        parent::__construct();
        $this->trunkPersons = $trunkPersons;
        $this->pairs = $pairs;
    }

    protected function configure($presenter) {
        parent::configure($presenter);

        //
        // data
        //
        $dataSource = new NDataSource($this->trunkPersons);
        $this->setDataSource($dataSource);

        //
        // columns
        //
        $that = $this;
        $this->addColumn('display_name_a', _('Osoba A'))->setRenderer(function($row) use($that) {
                            return $that->renderPerson($row);
                        })
                ->setSortable(false);
        $pairs = & $this->pairs;
        $this->addColumn('display_name_b', _('Osoba B'))->setRenderer(function($row) use($that, $pairs) {
                            return $that->renderPerson($pairs[$row->person_id][DuplicateFinder::IDX_PERSON]);
                        })
                ->setSortable(false);
        $this->addColumn('score', _('Podobnost'))->setRenderer(function($row) use($that, $pairs) {
                            return sprintf("%0.2f", $pairs[$row->person_id][DuplicateFinder::IDX_SCORE]);
                        })
                ->setSortable(false);

        //
        // operations
        //
        
        $this->addButton("mergeAB", _('Sloučit A<-B'))
                ->setText(_('Sloučit A<-B'))
                ->setClass("btn btn-xs btn-primary")
                ->setLink(function($row) use ($presenter, $pairs) {
                            return $presenter->link("Person:merge", array(
                                        'trunkId' => $row->person_id,
                                        'mergedId' => $pairs[$row->person_id][DuplicateFinder::IDX_PERSON]->person_id,
                            ));
                        })
                ->setShow(function($row) use ($presenter, $pairs) {
                            return $presenter->authorized("Person:merge", array(
                                        'trunkId' => $row->person_id,
                                        'mergedId' => $pairs[$row->person_id][DuplicateFinder::IDX_PERSON]->person_id,
                            ));
                        });
        $this->addButton("mergeBA", _('Sloučit B<-A'))
                ->setText(_('Sloučit B<-A'))
                ->setLink(function($row) use ($presenter, $pairs) {
                            return $presenter->link("Person:merge", array(
                                        'trunkId' => $pairs[$row->person_id][DuplicateFinder::IDX_PERSON]->person_id,
                                        'mergedId' => $row->person_id,
                            ));
                        })
                ->setShow(function($row) use ($presenter, $pairs) {
                            return $presenter->authorized("Person:merge", array(
                                        'trunkId' => $pairs[$row->person_id][DuplicateFinder::IDX_PERSON]->person_id,
                                        'mergedId' => $row->person_id,
                            ));
                        });


        //
        // appeareance
    //
        
    }

    private function renderPerson(ModelPerson $person) {
        $el = Html::el('span');
        $el->title('person.created ' . $person->created);
        $el->setText($person->getFullname() . ' (' . $person->person_id . ')');
        return $el;        
    }

}
