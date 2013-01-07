<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 *
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 * @package Nette\Forms
 */



/**
 * Creates, validates and renders HTML forms.
 *
 * @author     David Grudl
 *
 * @property   mixed $action
 * @property   string $method
 * @property-read array $groups
 * @property-read ITranslator|NULL $translator
 * @property-read bool $anchored
 * @property-read ISubmitterControl|FALSE $submitted
 * @property-read bool $success
 * @property-read array $httpData
 * @property-read array $errors
 * @property-read NHtml $elementPrototype
 * @property   IFormRenderer $renderer
 * @package Nette\Forms
 */
class NForm extends NFormContainer
{
	/** validator */
	const EQUAL = ':equal',
		IS_IN = ':equal',
		FILLED = ':filled',
		VALID = ':valid';

	// CSRF protection
	const PROTECTION = 'NHiddenField::validateEqual';

	// button
	const SUBMITTED = ':submitted';

	// text
	const MIN_LENGTH = ':minLength',
		MAX_LENGTH = ':maxLength',
		LENGTH = ':length',
		EMAIL = ':email',
		URL = ':url',
		REGEXP = ':regexp',
		PATTERN = ':pattern',
		INTEGER = ':integer',
		NUMERIC = ':integer',
		FLOAT = ':float',
		RANGE = ':range';

	// multiselect
	const COUNT = ':length';

	// file upload
	const MAX_FILE_SIZE = ':fileSize',
		MIME_TYPE = ':mimeType',
		IMAGE = ':image';

	/** method */
	const GET = 'get',
		POST = 'post';

	/** @internal tracker ID */
	const TRACKER_ID = '_form_';

	/** @internal protection token ID */
	const PROTECTOR_ID = '_token_';

	/** @var array of function(Form $sender); Occurs when the form is submitted and successfully validated */
	public $onSuccess;

	/** @var array of function(Form $sender); Occurs when the form is submitted and is not valid */
	public $onError;

	/** @var array of function(Form $sender); Occurs when the form is submitted */
	public $onSubmit;

	/** @deprecated */
	public $onInvalidSubmit;

	/** @var mixed or NULL meaning: not detected yet */
	private $submittedBy;

	/** @var array */
	private $httpData;

	/** @var NHtml  <form> element */
	private $element;

	/** @var IFormRenderer */
	private $renderer;

	/** @var ITranslator */
	private $translator;

	/** @var array of NFormGroup */
	private $groups = array();

	/** @var array */
	private $errors = array();



	/**
	 * Form constructor.
	 * @param  string
	 */
	public function __construct($name = NULL)
	{
		$this->element = NHtml::el('form');
		$this->element->action = ''; // RFC 1808 -> empty uri means 'this'
		$this->element->method = self::POST;
		$this->element->id = 'frm-' . $name;

		$this->monitor(__CLASS__);
		if ($name !== NULL) {
			$tracker = new NHiddenField($name);
			$tracker->unmonitor(__CLASS__);
			$this[self::TRACKER_ID] = $tracker;
		}
		parent::__construct(NULL, $name);
	}



	/**
	 * This method will be called when the component (or component's parent)
	 * becomes attached to a monitored object. Do not call this method yourself.
	 * @param  IComponent
	 * @return void
	 */
	protected function attached($obj)
	{
		if ($obj instanceof self) {
			throw new InvalidStateException('Nested forms are forbidden.');
		}
	}



	/**
	 * Returns self.
	 * @return NForm
	 */
	final public function getForm($need = TRUE)
	{
		return $this;
	}



	/**
	 * Sets form's action.
	 * @param  mixed URI
	 * @return NForm  provides a fluent interface
	 */
	public function setAction($url)
	{
		$this->element->action = $url;
		return $this;
	}



	/**
	 * Returns form's action.
	 * @return mixed URI
	 */
	public function getAction()
	{
		return $this->element->action;
	}



	/**
	 * Sets form's method.
	 * @param  string get | post
	 * @return NForm  provides a fluent interface
	 */
	public function setMethod($method)
	{
		if ($this->httpData !== NULL) {
			throw new InvalidStateException(__METHOD__ . '() must be called until the form is empty.');
		}
		$this->element->method = strtolower($method);
		return $this;
	}



	/**
	 * Returns form's method.
	 * @return string get | post
	 */
	public function getMethod()
	{
		return $this->element->method;
	}



	/**
	 * Cross-Site Request Forgery (CSRF) form protection.
	 * @param  string
	 * @param  int
	 * @return void
	 */
	public function addProtection($message = NULL, $timeout = NULL)
	{
		$session = $this->getSession()->getSection('Nette.Forms.Form/CSRF');
		$key = "key$timeout";
		if (isset($session->$key)) {
			$token = $session->$key;
		} else {
			$session->$key = $token = NStrings::random();
		}
		$session->setExpiration($timeout, $key);
		$this[self::PROTECTOR_ID] = new NHiddenField($token);
		$this[self::PROTECTOR_ID]->addRule(self::PROTECTION, $message, $token);
	}



	/**
	 * Adds fieldset group to the form.
	 * @param  string  caption
	 * @param  bool    set this group as current
	 * @return NFormGroup
	 */
	public function addGroup($caption = NULL, $setAsCurrent = TRUE)
	{
		$group = new NFormGroup;
		$group->setOption('label', $caption);
		$group->setOption('visual', TRUE);

		if ($setAsCurrent) {
			$this->setCurrentGroup($group);
		}

		if (isset($this->groups[$caption])) {
			return $this->groups[] = $group;
		} else {
			return $this->groups[$caption] = $group;
		}
	}



	/**
	 * Removes fieldset group from form.
	 * @param  string|FormGroup
	 * @return void
	 */
	public function removeGroup($name)
	{
		if (is_string($name) && isset($this->groups[$name])) {
			$group = $this->groups[$name];

		} elseif ($name instanceof NFormGroup && in_array($name, $this->groups, TRUE)) {
			$group = $name;
			$name = array_search($group, $this->groups, TRUE);

		} else {
			throw new InvalidArgumentException("Group not found in form '$this->name'");
		}

		foreach ($group->getControls() as $control) {
			$this->removeComponent($control);
		}

		unset($this->groups[$name]);
	}



	/**
	 * Returns all defined groups.
	 * @return array of FormGroup
	 */
	public function getGroups()
	{
		return $this->groups;
	}



	/**
	 * Returns the specified group.
	 * @param  string  name
	 * @return NFormGroup
	 */
	public function getGroup($name)
	{
		return isset($this->groups[$name]) ? $this->groups[$name] : NULL;
	}



	/********************* translator ****************d*g**/



	/**
	 * Sets translate adapter.
	 * @param  ITranslator
	 * @return NForm  provides a fluent interface
	 */
	public function setTranslator(ITranslator $translator = NULL)
	{
		$this->translator = $translator;
		return $this;
	}



	/**
	 * Returns translate adapter.
	 * @return ITranslator|NULL
	 */
	final public function getTranslator()
	{
		return $this->translator;
	}



	/********************* submission ****************d*g**/



	/**
	 * Tells if the form is anchored.
	 * @return bool
	 */
	public function isAnchored()
	{
		return TRUE;
	}



	/**
	 * Tells if the form was submitted.
	 * @return ISubmitterControl|FALSE  submittor control
	 */
	final public function isSubmitted()
	{
		if ($this->submittedBy === NULL && count($this->getControls())) {
			$this->getHttpData();
			$this->submittedBy = !empty($this->httpData);
		}
		return $this->submittedBy;
	}



	/**
	 * Tells if the form was submitted and successfully validated.
	 * @return bool
	 */
	final public function isSuccess()
	{
		return $this->isSubmitted() && $this->isValid();
	}



	/**
	 * Sets the submittor control.
	 * @param  ISubmitterControl
	 * @return NForm  provides a fluent interface
	 */
	public function setSubmittedBy(ISubmitterControl $by = NULL)
	{
		$this->submittedBy = $by === NULL ? FALSE : $by;
		return $this;
	}



	/**
	 * Returns submitted HTTP data.
	 * @return array
	 */
	final public function getHttpData()
	{
		if ($this->httpData === NULL) {
			if (!$this->isAnchored()) {
				throw new InvalidStateException('Form is not anchored and therefore can not determine whether it was submitted.');
			}
			$this->httpData = (array) $this->receiveHttpData();
		}
		return $this->httpData;
	}



	/**
	 * Fires submit/click events.
	 * @return void
	 */
	public function fireEvents()
	{
		if (!$this->isSubmitted()) {
			return;

		} elseif ($this->submittedBy instanceof ISubmitterControl) {
			if (!$this->submittedBy->getValidationScope() || $this->isValid()) {
				$this->submittedBy->click();
				$valid = TRUE;
			} else {
				$this->submittedBy->onInvalidClick($this->submittedBy);
			}
		}

		if (isset($valid) || $this->isValid()) {
			$this->onSuccess($this);
		} else {
			$this->onError($this);
			if ($this->onInvalidSubmit) {
				trigger_error(__CLASS__ . '->onInvalidSubmit is deprecated; use onError instead.', E_USER_WARNING);
				$this->onInvalidSubmit($this);
			}
		}

		if ($this->onSuccess) { // back compatibility
			$this->onSubmit($this);
		} elseif ($this->onSubmit) {
			trigger_error(__CLASS__ . '->onSubmit changed its behavior; use onSuccess instead.', E_USER_WARNING);
			if (isset($valid) || $this->isValid()) {
				$this->onSubmit($this);
			}
		}
	}



	/**
	 * Internal: receives submitted HTTP data.
	 * @return array
	 */
	protected function receiveHttpData()
	{
		$httpRequest = $this->getHttpRequest();
		if (strcasecmp($this->getMethod(), $httpRequest->getMethod())) {
			return;
		}

		if ($httpRequest->isMethod('post')) {
			$data = NArrays::mergeTree($httpRequest->getPost(), $httpRequest->getFiles());
		} else {
			$data = $httpRequest->getQuery();
		}

		if ($tracker = $this->getComponent(self::TRACKER_ID, FALSE)) {
			if (!isset($data[self::TRACKER_ID]) || $data[self::TRACKER_ID] !== $tracker->getValue()) {
				return;
			}
		}

		return $data;
	}



	/********************* data exchange ****************d*g**/



	/**
	 * Returns the values submitted by the form.
	 * @return array
	 */
	public function getValues()
	{
		$values = parent::getValues();
		unset($values[self::TRACKER_ID], $values[self::PROTECTOR_ID]);
		return $values;
	}



	/********************* validation ****************d*g**/



	/**
	 * Adds error message to the list.
	 * @param  string  error message
	 * @return void
	 */
	public function addError($message)
	{
		$this->valid = FALSE;
		if ($message !== NULL && !in_array($message, $this->errors, TRUE)) {
			$this->errors[] = $message;
		}
	}



	/**
	 * Returns validation errors.
	 * @return array
	 */
	public function getErrors()
	{
		return $this->errors;
	}



	/**
	 * @return bool
	 */
	public function hasErrors()
	{
		return (bool) $this->getErrors();
	}



	/**
	 * @return void
	 */
	public function cleanErrors()
	{
		$this->errors = array();
		$this->valid = NULL;
	}



	/********************* rendering ****************d*g**/



	/**
	 * Returns form's HTML element template.
	 * @return NHtml
	 */
	public function getElementPrototype()
	{
		return $this->element;
	}



	/**
	 * Sets form renderer.
	 * @param  IFormRenderer
	 * @return NForm  provides a fluent interface
	 */
	public function setRenderer(IFormRenderer $renderer)
	{
		$this->renderer = $renderer;
		return $this;
	}



	/**
	 * Returns form renderer.
	 * @return IFormRenderer
	 */
	final public function getRenderer()
	{
		if ($this->renderer === NULL) {
			$this->renderer = new NDefaultFormRenderer;
		}
		return $this->renderer;
	}



	/**
	 * Renders form.
	 * @return void
	 */
	public function render()
	{
		$args = func_get_args();
		array_unshift($args, $this);
		echo call_user_func_array(array($this->getRenderer(), 'render'), $args);
	}



	/**
	 * Renders form to string.
	 * @return bool  can throw exceptions? (hidden parameter)
	 * @return string
	 */
	public function __toString()
	{
		try {
			return $this->getRenderer()->render($this);

		} catch (Exception $e) {
			if (func_get_args() && func_get_arg(0)) {
				throw $e;
			} else {
				NDebugger::toStringException($e);
			}
		}
	}



	/********************* backend ****************d*g**/



	/**
	 * @return IHttpRequest
	 */
	protected function getHttpRequest()
	{
		return NEnvironment::getHttpRequest();
	}



	/**
	 * @return NSession
	 */
	protected function getSession()
	{
		return NEnvironment::getSession();
	}

}
