<?php

namespace HighSolutions\EloquentSequence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Psr\Log\InvalidArgumentException;

class SequenceService {
	
	/**
	 * @var \Illuminate\Database\Eloquent\Model
	 */
	protected $obj;

	/**
	 * @var array
	 */
	protected $config;

    /**
     * @param \Illuminate\Database\Eloquent\Model $obj
     * @return $this
     */
    public function setModel(Model $obj)
    {
        $this->obj = $obj;
        $this->pullConfigurationFromModel();
        return $this;
    }

    /**
     * Assign configuration of stored Model object to $this->config.
     * 
     * @return void
     */
    protected function pullConfigurationFromModel()
    {
    	$this->config = $this->getDefaultConfiguration();

    	foreach($this->obj->sequence() as $key => $value) {
    		$this->config[$key] = $value;
    	}
    }

    /**
     * Returns default configuration of service.
     * 
     * @return bool
     */
    protected function getDefaultConfiguration() 
    {
    	return [
    		'group' => '',
    		'fieldName' => 'seq',
    		'exceptions' => false,
    		'orderFrom1' => false,
    	];
    }

	/**
	 * Assign sequence attribute to model.
	 * 
	 * @param \Illuminate\Database\Eloquent\Model $obj
	 * @return void
	 */
	public function assignSequence(Model $obj) {
		$this->setModel($obj);

		if($this->isAlreadyAssigned() == true)
			return;
	
		$query = $this->prepareQuery();
		$this->calculateSequenceForObject($query);
	}

	/**
	 * Check if sequence attribute is already assigned. If yes, terminate operation.
	 * 
	 * @return bool
	 */
	protected function isAlreadyAssigned()
	{
		return $this->obj->{$this->getSequenceConfig('fieldName')} != 0;
	}

	/**
	 * Get value from configuration of given key.
	 * 
	 * @param string $key
	 * @return mixed
	 */
	protected function getSequenceConfig(string $key) 
	{
		if(isset($this->config[$key]))
			return $this->config[$key];

		throw new InvalidArgumentException("There is no specific key ({$key}) in Sequence configuration.");
	}

	/**
	 * Check if group configuration is set.
	 * 
	 * @return bool
	 */
	protected function isGroupProvided()
	{
		$group = $this->getSequenceConfig('group');
		return $group !== null && $group !== '';
	}

	/**
	 * Prepares query based on group configuration.
	 * 
	 * @return Model
	 */
	protected function prepareQuery()
	{
		$query = $this->obj->newQuery();
		return $this->fillQueryWithGroupConditions($query);
	}

	/**
	 * Fills query with where clauses for specified group configuration.
	 * 
	 * @param \Illuminate\Database\Eloquent\Model $query
	 * @return \Illuminate\Database\Eloquent\Model
	 */
	protected function fillQueryWithGroupConditions($query) 
	{
		if($this->isGroupProvided() == true) {
			$groups = $this->getSequenceConfig('group');
			if(is_array($groups) == false)
				$groups = [$groups];

			foreach($groups as $group)
				$query = $query->where($group, $this->obj->{$group});
		}

		return $query;
	}

	/**
	 * Assign calculated sequence value to given object
	 * 
	 * @param \Illuminate\Database\Eloquent\Model
	 * @return void
	 */
	protected function calculateSequenceForObject($query)
	{
		$this->obj->{$this->getSequenceConfig('fieldName')} = $query->max($this->getSequenceConfig('fieldName')) + 1;
	}

	/**
	 * Update sequence attribute to models with sequence number greater than delering object.
	 * 
	 * @param \Illuminate\Database\Eloquent\Model $obj
	 * @return void
	 */
	public function updateSequences(Model $obj) {
		$this->setModel($obj);

		$query = $this->prepareQueryWithObjectsNeedingUpdate();		
		$query = $this->fillQueryWithGroupConditions($query);
		$this->decrementObjects($query);
	}

	/**
	 * Prepares query with objects stored in database with sequence number greater than deleteting object.
	 * 
	 * @return \Illuminate\Support\Facades\DB
	 */
	protected function prepareQueryWithObjectsNeedingUpdate()
	{
		return DB::table($this->obj->getTable())
					->where($this->getSequenceConfig('fieldName'), '>', $this->obj->{$this->getSequenceConfig('fieldName')});	
	}

	/**
	 * Execute query with decrementing sequence attribute for objects that fulfills conditions.
	 * 
	 * @param \Illuminate\Support\Facades\DB $query
	 * @return void
	 */
	protected function decrementObjects($query)
	{
		$query->decrement($this->getSequenceConfig('fieldName'));		
	}

	/**
	 * Move object one position earlier.
	 * 
	 * @param Model $obj
	 * @return Model
	 */
	public function moveUp(Model $obj)
	{
		$this->setModel($obj);
		return $this->moveObject($this->getPreviousObject());
	}

	/**
	 * Move ojbect one position lower.
	 * 
	 * @param Model $obj
	 * @return Model
	 */
	public function moveDown(Model $obj)
	{
		$this->setModel($obj);
		return $this->moveObject($this->getNextObject());
	}

	/**
	 * Swap position between two objects.
	 * 
	 * @param Model $obj
	 * @return Model
	 * @throws ModelNotFoundException
	 */
	private function moveObject(Model $secondObj)
	{
		if($secondObj == null) {
			if($this->getSequenceConfig('exceptions'))
				throw new ModelNotFoundException();
			return $this->obj;
		}

		$currentSequence = $this->getSequence($this->obj);
		$this->setSequence($this->obj, $this->getSequence($secondObj));
		$this->setSequence($secondObj, $currentSequence);

		return $this->obj;
	}

	/**
	 * Returns position of object.
	 * 
	 * @param Model $obj
	 * @return Model
	 */
	private function getSequence($obj)
	{
		return $obj->{$this->getSequenceConfig('fieldName')};
	}

	/**
	 * Sets position of object with value.
	 * 
	 * @param Model $obj
	 * @param int $value
	 * @return void
	 */
	private function setSequence(&$obj, $value)
	{
		$obj->{$this->getSequenceConfig('fieldName')} = $value;
		$obj->save();
	}

	/**
	 * Returns object one position earlier than base one.
	 * 
	 * @return Model
	 */
	private function getPreviousObject()
	{
		return $this->getNearObject(true);
	}

	/**
	 * Returns object one position later than base one.
	 * 
	 * @return Model
	 */
	private function getNextObject()
	{
		return $this->getNearObject(false);
	}

	/**
	 * Returns object one position earlier/later than base one.
	 * 
	 * @param bool $earlier
	 * @return Model
	 */
	private function getNearObject($earlier)
	{
		$currentSequence = $this->getSequence($this->obj);
		$query = $this->prepareQuery();
		$condition = $earlier ? '<' : '>';

		return $query->where($this->getSequenceConfig('fieldName'), $condition, $currentSequence)
			->sequenced()
			->first();
	}

	/**
	 * Move object to another positon.
	 * 
	 * @param Model $obj
	 * @param int $position
	 * @return Model
	 */
	public function moveTo(Model $obj, $position)
	{
		$this->setModel($obj);
		if(!$this->getSequenceConfig('orderFrom1'))
			$position++;

		$currentSequence = $this->getSequence($this->obj);
		if($currentSequence == $position)
			return $obj;

		if($currentSequence < $position)
			return $this->moveFurther($position);

		return $this->moveEarlier($position);
	}

	protected function moveFurther($position)
	{
		$query = $this->prepareQuery();
		$currentSequence = $this->getSequence($this->obj);

		$query->where($this->getSequenceConfig('fieldName'), '>', $currentSequence)
			->where($this->getSequenceConfig('fieldName'), '<=', $position)
			->sequenced()
			->get()
			->each
			->decrement($this->getSequenceConfig('fieldName'));

		$this->setSequence($this->obj, $position);

		return $this->obj;
	}


	protected function moveEarlier($position)
	{
		$query = $this->prepareQuery();
		$currentSequence = $this->getSequence($this->obj);

		$query->where($this->getSequenceConfig('fieldName'), '>=', $position)
			->where($this->getSequenceConfig('fieldName'), '<', $currentSequence)
			->sequenced()
			->get()
			->each
			->increment($this->getSequenceConfig('fieldName'));

		$this->setSequence($this->obj, $position);

		return $this->obj;
		
	}

}