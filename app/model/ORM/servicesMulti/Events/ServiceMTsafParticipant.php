<?php

namespace ORM\ServicesMulti\Events;

use AbstractServiceMulti;
use ORM\IModel;
use ORM\ModelsMulti\Events\ModelMTsafParticipant;
use ORM\Services\Events\ServiceTsafParticipant;
use ServiceEventParticipant;

/**
 * @author Michal Koutný <xm.koutny@gmail.com>
 */
class ServiceMTsafParticipant extends AbstractServiceMulti {

    protected $modelClassName = 'ORM\ModelsMulti\Events\ModelMTsafParticipant';
    protected $joiningColumn = 'event_participant_id';

    public function __construct(ServiceEventParticipant $mainService, ServiceTsafParticipant $joinedService) {
        parent::__construct($mainService, $joinedService);
    }

    /**
     * @param ModelMTsafParticipant $model
     */
    public function dispose(IModel $model) {
        parent::dispose($model);
        $this->getMainService()->dispose($model->getMainModel());
    }

}

