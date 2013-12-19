<?php

namespace FKSDB\Components\Grids;

use ModelPerson;
use NiftyGrid\DataSource\NDataSource;
use ServiceContestant;

/**
 *
 * @author Michal Koutný <xm.koutny@gmail.com>
 */
class ContestantsGrid extends BaseGrid {

    /**
     * @var ServiceContestant
     */
    private $serviceContestant;

    function __construct(ServiceContestant $serviceContestant) {
        parent::__construct();

        $this->serviceContestant = $serviceContestant;
    }

    protected function configure($presenter) {
        parent::configure($presenter);

        //
        // data
        //
        $contestants = $this->serviceContestant->getCurrentContestants($presenter->getSelectedContest()->contest_id, $presenter->getSelectedYear());

        $this->setDataSource(new NDataSource($contestants));
        $this->setDefaultOrder('family_name, other_name ASC');

        //
        // columns
        //
        $this->addColumn('display_name', _('Jméno'))->setRenderer(function($row) {
                    $person = ModelPerson::createFromTableRow($row);
                    return $person->getFullname();
                });
        $this->addColumn('study_year', _('Ročník'));
        $this->addColumn('school_name', _('Škola'));

        //
        // operations
        //
        $that = $this;
        $this->addButton("edit", _("Upravit"))
                ->setText('Upravit') //todo i18n
                ->setLink(function($row) use ($that) {
                            return $that->getPresenter()->link("edit", $row->ct_id);
                        });
        $this->addButton("editPerson", _("Upravit osobu"))
                ->setText('Upravit osobu') //todo i18n
                ->setLink(function($row) use ($presenter) {
                            return $presenter->link("Person:edit", array(
                                        'id' => $row->person_id,
                            ));
                        });

        $this->addGlobalButton('add')
                ->setLabel('Založit řešitele')
                ->setLink($this->getPresenter()->link('create'));


        //
        // appeareance
        //
        $this->paginate = false;
    }

}
