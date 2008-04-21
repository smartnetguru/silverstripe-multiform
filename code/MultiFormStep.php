<?php

/**
 * MultiFormStep controls the behaviour of a single from step in the multi-form
 * process. All form steps should be subclasses of this class, as it encapsulates
 * the functionality required for the step to be aware of itself in the form step
 * process.
 * 
 * @package multiform
 */
class MultiFormStep extends DataObject {

	static $db = array(
		'Data' => 'Text' // stores serialized maps with all session information
	);
	
	static $has_one = array(
		'Session' => 'MultiFormSession'
	);
	
	/**
	 * Centerpiece of the flow control for the form.
	 * If set to a string, you pretty much have a linear
	 * form flow - if set to an array, you should
	 * use {@link getNextStep()} to enact flow control
	 * and branching to different form steps,
	 * most likely based on previously set session data
	 * (e.g. a checkbox field or a dropdown).
	 *
	 * @var array|string
	 */
	protected static $next_steps;
	
	/**
	 * Each {@link MultiForm} subclass needs at least
	 * one step which is marked as the "final" one
	 * and triggers the {@link MultiForm->finish()}
	 * method that wraps up the whole submission.
	 *
	 * @var boolean
	 */
	protected static $is_final_step = false;

	/**
	 * Title of this step, can be used by each step that sub-classes this.
	 * It's useful for creating a list of steps in your template.
	 *
	 * @var string
	 */
	protected $title;
	
	/**
	 * Form fields to be rendered with this step.
	 * (Form object is created in {@link MultiForm}.
	 * 
	 * This function needs to be implemented on your
	 * subclasses of MultiFormStep
	 *
	 * @return FieldSet
	 */
	public function getFields() {
		user_error('Please implement getFields on your MultiFormStep subclass', E_USER_ERROR);
	}
	
	/**
	 * Additional form actions to be rendered with this step.
	 * (Form object is created in {@link MultiForm}.
	 * 
	 * Note: This is optional, and is to be implemented
	 * on your subclasses of MultiFormStep
	 * 
	 * @return FieldSet
	 */
	public function getExtraActions() {
		return new FieldSet();
	}
	
	/**
	 * Get a validator specific to this form.
	 * 
	 * @return Validator
	 */
	public function getValidator() {
		return false;
	}
	
	/**
	 * Accessor method for $this->title
	 * 
	 * @return string Title of this step
	 */
	public function getTitle() {
		return $this->title;
	}
	
	/**
	 * Gets a direct link to this step (only works
	 * if you're allowed to skip steps, or this step
	 * has already been saved to the database
	 * for the current {@link MultiFormSession}).
	 *
	 * @return string Relative URL to this step
	 */
	public function Link() {
		$id = $this->Session()->Hash ? $this->Session()->Hash : $this->Session()->ID;
		return Controller::curr()->Link() . '?MultiFormSessionID=' . $id;
	}

	/**
	 * Unserialize stored session data and return it.
	 * This should be called when the form is constructed,
	 * so the fields can be loaded with the values.
	 */
	public function loadData() {
		return unserialize($this->Data);
	}
	
	/**
	 * Save the data for this step into session, serializing it first.
	 *
	 * @param array $data The processed data from save() on MultiForm
	 */
	public function saveData($data) {
		$this->Data = serialize($data);
		$this->write();
	}
	
	/**
	 * Returns the first value of $next_step
	 * 
	 * @return String Classname of a {@link MultiFormStep} subclass
	 */
	public function getNextStep() {
		$nextSteps = $this->stat('next_steps');

		// Check if next_steps have been implemented properly if not the final step
		if(!$this->isFinalStep()) {
			if(!isset($nextSteps)) user_error('MultiFormStep->getNextStep(): Please define at least one $next_steps on ' . $this->class, E_USER_ERROR);
		}
		
		if(is_string($nextSteps)) {
			return $nextSteps;
		} elseif(is_array($nextSteps) && count($nextSteps)) {
			// custom flow control goes here
			return $nextSteps[0];
		} else {
			return false;
		}
	}

	/**
	 * Returns the next step to the current step in the database.
	 * 
	 * This will only return something if you've previously visited
	 * the step ahead of the current step, and then gone back a step.
	 * 
	 * @return MultiFormStep|boolean
	 */
	public function getNextStepFromDatabase() {
		if($this->SessionID) {
			$nextSteps = $this->stat('next_steps');
			if(is_string($nextSteps)) {
				return DataObject::get_one($nextSteps, "SessionID = {$this->SessionID}");
			} elseif(is_array($nextSteps)) {
				return DataObject::get_one($nextSteps[0], "SessionID = {$this->SessionID}");
			} else {
				return false;
			}
		}
	}
	
	/**
	 * Accessor method for self::$next_steps
	 */
	public function getNextSteps() {
		return $this->stat('next_steps');
	}
	
	/**
	 * Returns the previous step, if there is one.
	 * 
	 * To determine if there is a previous step, we check the database to see if there's
	 * a previous step for this multi form session ID.
	 * 
	 * @return String Classname of a {@link MultiFormStep} subclass
	 */
	public function getPreviousStep() {
		$steps = DataObject::get('MultiFormStep', "SessionID = {$this->SessionID}", 'LastEdited DESC');
		if($steps) {
			foreach($steps as $step) {
				if($step->getNextStep()) {
					if($step->getNextStep() == $this->class) {
						return $step->class;
					}
				}
			}
		}
	}
	
	/**
	 * Retrieves the previous step record from the database.
	 *
	 * @return MultiFormStep subclass
	 */
	public function getPreviousStepFromDatabase() {
		if($prevStepClass = $this->getPreviousStep()) {
			return DataObject::get_one($prevStepClass, "SessionID = {$this->SessionID}");	
		}
	}
	
	// ##################### Utility ####################
	
	/**
	 * Determines whether this step is the final step in the multi-step process or not,
	 * based on the variable $is_final_step - to set the final step, create this variable
	 * on your form step class.
	 *
	 * @return boolean
	 */
	public function isFinalStep() {
		return $this->stat('is_final_step');
	}
	
	/**
	 * Determines whether the currently viewed step is the current step set in the session.
	 * This assumes you are checking isCurrentStep() against a data record of a MultiFormStep
	 * subclass, otherwise it doesn't work. An example of this is using a singleton instance - it won't
	 * work because there's no data.
	 * 
	 * @return boolean
	 */
	public function isCurrentStep() {
		if($this->class == $this->Session()->CurrentStep()->class) return true;
	}
	
}

?>