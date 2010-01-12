<?php

/**
 * @file classes/form/Form.inc.php
 *
 * Copyright (c) 2000-2009 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class Form
 * @ingroup core
 *
 * @brief Class defining basic operations for handling HTML forms.
 */

// $Id$


import('form.FormError');
import('form.validation.FormValidator');

class Form {

	/** The template file containing the HTML form */
	var $_template;

	/** Associative array containing form data */
	var $_data;

	/** Validation checks for this form */
	var $_checks;

	/** Errors occurring in form validation */
	var $_errors;

	/** Array of field names where an error occurred and the associated error message */
	var $errorsArray;

	/** Array of field names where an error occurred */
	var $errorFields;

	/** Array of errors for the form section currently being processed */
	var $formSectionErrors;

	/** Styles organized by parameter name */
	var $fbvStyles;

	/**
	 * Constructor.
	 * @param $template string the path to the form template file
	 */
	function Form($template, $callHooks = true) {
		if ($callHooks === true && checkPhpVersion('4.3.0')) {
			$trace = debug_backtrace();
			// Call hooks based on the calling entity, assuming
			// this method is only called by a subclass. Results
			// in hook calls named e.g. "papergalleyform::Constructor"
			// Note that class names are always lower case.
			HookRegistry::call(strtolower($trace[1]['class']) . '::Constructor', array(&$this, &$template));
		}

		$this->_template = $template;
		$this->_data = array();
		$this->_checks = array();
		$this->_errors = array();
		$this->errorsArray = array();
		$this->errorFields = array();
		$this->formSectionErrors = array();
		$this->fbvStyles = array(
				'size' => array('SMALL' => 'SMALL', 'MEDIUM' => 'MEDIUM', 'LARGE' => 'LARGE'),
				'float' => array('RIGHT' => 'RIGHT', 'LEFT' => 'LEFT'),
				'measure' => array('1OF2' => '1OF2', '3OF4' => '3OF4', '2OF3' => '2OF3'),
				'layout' => array('THREE_COLUMNS' => 'THREE_COLUMNS', 'TWO_COLUMNS' => 'TWO_COLUMNS', 'ONE_COLUMN' => 'ONE_COLUMN')
			);
	}

	/**
	 * Display the form.
	 */
	function display() {
		$templateMgr =& TemplateManager::getManager();
		$templateMgr->setCacheability(CACHEABILITY_NO_STORE);
		$templateMgr->register_function('fieldLabel', array(&$this, 'smartyFieldLabel'));
		$templateMgr->register_function('form_language_chooser', array(&$this, 'smartyFormLanguageChooser'));
		$templateMgr->register_function('modal_language_chooser', array(&$this, 'smartyModalLanguageChooser'));
		$templateMgr->register_block('form_locale_iterator', array(&$this, 'formLocaleIterator'));

		// modifier vocabulary for creating forms
		$templateMgr->register_block('fbvFormSection', array(&$this, 'smartyFBVFormSection'));
		$templateMgr->register_block('fbvCustomElement', array(&$this, 'smartyFBVCustomElement'));
		$templateMgr->register_block('fbvFormArea', array(&$this, 'smartyFBVFormArea'));
		$templateMgr->register_function('fbvButton', array(&$this, 'smartyFBVButton'));
		$templateMgr->register_function('fbvTextInput', array(&$this, 'smartyFBVTextInput'));
		$templateMgr->register_function('fbvTextarea', array(&$this, 'smartyFBVTextArea'));
		$templateMgr->register_function('fbvSelect', array(&$this, 'smartyFBVSelect'));
		$templateMgr->register_function('fbvElement', array(&$this, 'smartyFBVElement'));
		$templateMgr->register_function('fbvCheckbox', array(&$this, 'smartyFBVCheckbox'));
		$templateMgr->register_function('fbvRadioButton', array(&$this, 'smartyFBVRadioButton'));

		$templateMgr->assign('fbvStyles', $this->fbvStyles);

		$templateMgr->assign($this->_data);
		$templateMgr->assign('isError', !$this->isValid());
		$templateMgr->assign('errors', $this->getErrorsArray());

		$templateMgr->assign('formLocales', Locale::getSupportedFormLocales());

		// Determine the current locale to display fields with
		$formLocale = Request::getUserVar('formLocale');
		if (empty($formLocale) || !in_array($formLocale, array_keys(Locale::getAllLocales()))) {
			$formLocale = Locale::getLocale();
		}
		$templateMgr->assign('formLocale', $formLocale);

		$templateMgr->display($this->_template);
	}

	/**
	 * Get the value of a form field.
	 * @param $key string
	 * @return mixed
	 */
	function getData($key) {
		return isset($this->_data[$key]) ? $this->_data[$key] : null;
	}

	/**
	 * Set the value of a form field.
	 * @param $key
	 * @param $value
	 */
	function setData($key, $value) {

		if (is_string($value)) $value = Core::cleanVar($value);

		$this->_data[$key] = $value;
	}

	/**
	 * Initialize form data for a new form.
	 */
	function initData() {
	}

	/**
	 * Assign form data to user-submitted data.
	 */
	function readInputData() {
	}

	/**
	 * Validate form data.
	 */
	function validate($callHooks = true) {
		if (!isset($this->errorsArray)) {
			$this->getErrorsArray();
		}

		foreach ($this->_checks as $check) {
			// WARNING: This line is for PHP4 compatibility when
			// instantiating forms without reference. Should not
			// be removed or otherwise used.
			// See http://pkp.sfu.ca/wiki/index.php/Information_for_Developers#Use_of_.24this_in_the_constructur
			// For an explanation why we have to replace the reference to $this here.
			$check->_setForm($this);

			if (!isset($this->errorsArray[$check->getField()]) && !$check->isValid()) {
				if (method_exists($check, 'getErrorFields') && method_exists($check, 'isArray') && call_user_func(array(&$check, 'isArray'))) {
					$errorFields = call_user_func(array(&$check, 'getErrorFields'));
					for ($i=0, $count=count($errorFields); $i < $count; $i++) {
						$this->addError($errorFields[$i], $check->getMessage());
						$this->errorFields[$errorFields[$i]] = 1;
					}
				} else {
					$this->addError($check->getField(), $check->getMessage());
					$this->errorFields[$check->getField()] = 1;
				}
			}
		}

		if ($callHooks === true && checkPhpVersion('4.3.0')) {
			$trace = debug_backtrace();
			// Call hooks based on the calling entity, assuming
			// this method is only called by a subclass. Results
			// in hook calls named e.g. "papergalleyform::validate"
			// Note that class and function names are always lower
			// case.
			$value = null;
			if (HookRegistry::call(strtolower($trace[0]['class'] . '::' . $trace[0]['function']), array(&$this, &$value))) {
				return $value;
			}
		}

		return $this->isValid();
	}

	/**
	 * Execute the form's action.
	 * (Note that it is assumed that the form has already been validated.)
	 */
	function execute() {
	}

	/**
	 * Get the list of field names that need to support multiple locales
	 * @return array
	 */
	function getLocaleFieldNames() {
		return array();
	}

	/**
	 * Determine whether or not the current request results from a resubmit
	 * of locale data resulting from a form language change.
	 * @return boolean
	 */
	function isLocaleResubmit() {
		$formLocale = Request::getUserVar('formLocale');
		return (!empty($formLocale));
	}

	/**
	 * Get the current form locale.
	 * @return string
	 */
	function getFormLocale() {
		$formLocale = Request::getUserVar('formLocale');
		if (empty($formLocale)) $formLocale = Locale::getLocale();
		return $formLocale;
	}

	/**
	 * Adds specified user variables to input data.
	 * @param $vars array the names of the variables to read
	 */
	function readUserVars($vars) {
		foreach ($vars as $k) {
			$this->setData($k, Request::getUserVar($k));
		}
	}

	/**
	 * Adds specified user date variables to input data.
	 * @param $vars array the names of the date variables to read
	 */
	function readUserDateVars($vars) {
		foreach ($vars as $k) {
			$this->setData($k, Request::getUserDateVar($k));
		}
	}

	/**
	 * Add a validation check to the form.
	 * @param $formValidator FormValidator
	 */
	function addCheck($formValidator) {
		$this->_checks[] =& $formValidator;
	}

	/**
	 * Add an error to the form.
	 * Errors are typically assigned as the form is validated.
	 * @param $field string the name of the field where the error occurred
	 */
	function addError($field, $message) {
		$this->_errors[] = new FormError($field, $message);
	}

	/**
	 * Add an error field for highlighting on form
	 * @param $field string the name of the field where the error occurred
	 */
	function addErrorField($field) {
		$this->errorFields[$field] = 1;
	}

	/**
	 * Check if form passes all validation checks.
	 * @return boolean
	 */
	function isValid() {
		return empty($this->_errors);
	}

	/**
	 * Return set of errors that occurred in form validation.
	 * If multiple errors occurred processing a single field, only the first error is included.
	 * @return array erroneous fields and associated error messages
	 */
	function getErrorsArray() {
		$this->errorsArray = array();
		foreach ($this->_errors as $error) {
			if (!isset($this->errorsArray[$error->getField()])) {
				$this->errorsArray[$error->getField()] = $error->getMessage();
			}
		}
		return $this->errorsArray;
	}

	/**
	 * Custom Smarty function for labelling/highlighting of form fields.
	 * @param $params array can contain 'name' (field name/ID), 'required' (required field), 'key' (localization key), 'label' (non-localized label string), 'suppressId' (boolean)
	 * @param $smarty Smarty
	 */
	function smartyFieldLabel($params, &$smarty) {
		$returner = '';
		if (isset($params) && !empty($params)) {
			if (isset($params['key'])) {
				$params['label'] = Locale::translate($params['key'], $params);
			}

			if (isset($this->errorFields[$params['name']])) {
				$class = ' class="error"';
			} else {
				$class = '';
			}
			$returner = '<label' . (isset($params['suppressId']) ? '' : ' for="' . $params['name'] . '"') . $class . '>' . $params['label'] . (isset($params['required']) && !empty($params['required']) ? '*' : '') . '</label>';
		}
		return $returner;
	}

	function _decomposeArray($name, $value, $stack) {
		$returner = '';
		if (is_array($value)) {
			foreach ($value as $key => $subValue) {
				$newStack = $stack;
				$newStack[] = $key;
				$returner .= $this->_decomposeArray($name, $subValue, $newStack);
			}
		} else {
			$name = htmlentities($name, ENT_COMPAT, LOCALE_ENCODING);
			$value = htmlentities($value, ENT_COMPAT, LOCALE_ENCODING);
			$returner .= '<input type="hidden" name="' . $name;
			while (($item = array_shift($stack)) !== null) {
				$item = htmlentities($item, ENT_COMPAT, LOCALE_ENCODING);
				$returner .= '[' . $item . ']';
			}
			$returner .= '" value="' . $value . "\" />\n";
		}
		return $returner;
	}

	/**
	 * Add hidden form parameters for the localized fields for this form
	 * and display the language chooser field
	 * @param $params array
	 * @param $smarty object
	 */
	function smartyFormLanguageChooser($params, &$smarty) {
		$returner = '';

		// Print back all non-current language field values so that they
		// are not lost.
		$formLocale = $smarty->get_template_vars('formLocale');
		foreach ($this->getLocaleFieldNames() as $field) {
			$values = $this->getData($field);
			if (!is_array($values)) continue;
			foreach ($values as $locale => $value) {
				if ($locale != $formLocale) $returner .= $this->_decomposeArray($field, $value, array($locale));
			}
		}

		// Display the language selector widget.
		$formLocale = $smarty->get_template_vars('formLocale');
		$returner .= '<div id="languageSelector"><select size="1" name="formLocale" id="formLocale" onchange="changeFormAction(\'' . htmlentities($params['form'], ENT_COMPAT, LOCALE_ENCODING) . '\', \'' . htmlentities($params['url'], ENT_QUOTES, LOCALE_ENCODING) . '\')" class="selectMenu">';
		foreach (Locale::getSupportedLocales() as $locale => $name) {
			$returner .= '<option ' . ($locale == $formLocale?'selected="selected" ':'') . 'value="' . htmlentities($locale, ENT_COMPAT, LOCALE_ENCODING) . '">' . htmlentities($name, ENT_COMPAT, LOCALE_ENCODING) . '</option>';
		}
		$returner .= '</select></div>';
		return $returner;
	}


	/**
	 * Add hidden form parameters for the localized fields for modal dialogs
	 * and display the language chooser field
	 * @params $params array associative array
	 * @params $smarty Smarty
	 * @return string Call to modal function with specified parameters
	 */
	function smartyModalLanguageChooser($params, &$smarty) {
		// Display the language selector widget.
		$formLocale = $smarty->get_template_vars('formLocale');
		$returner = "<input type='hidden' id='currentLocale' value=$formLocale>";
		$returner .= '<div id="languageSelector"><select size="1" name="formLocale" id="formLocale" onChange="changeModalFormLocale()" class="selectMenu">';
		foreach (Locale::getSupportedLocales() as $locale => $name) {
			$returner .= '<option ' . ($locale == $formLocale?'selected="selected" ':'') . 'value="' . htmlentities($locale, ENT_COMPAT, LOCALE_ENCODING) . '">' . htmlentities($name, ENT_COMPAT, LOCALE_ENCODING) . '</option>';
		}
		$returner .= '</select></div>';
		return $returner;
	}

	/**
	 * Iterator function for locales (prints a form field once for each locale).
	 */
	function formLocaleIterator($params, $content, &$smarty, &$repeat) {
		$elementType = $params['type'];
		$currentLocale = Locale::getPrimaryLocale();

		if(!$repeat) {
			foreach (Locale::getSupportedLocales() as $locale => $name) {
				$style = '';
				$currentContent = $content;
				if ($locale != $currentLocale) {
					$currentContent = str_replace($currentLocale, $locale, $currentContent);
//					$currentContent = preg_replace(array('/rule_required/', '/rule_email/', '/rule_url/', '/rule_date/'), '', $currentContent);
					$style = 'display:none;';
				}
				echo "<div class='$locale' style='$style'>$currentContent</div>";
			}
		}
	}

	/** form builder vocabulary - FBV */

	/**
	 * Retrieve style info associated with style constants.
	 * @param $category string 
	 * @param $value string
	 */
	function getStyleInfoByIdentifier($category, $value) {
		$returner = null;
		switch ($category) {
			case 'size':
				switch($value) {
					case 'SMALL': $returner = 'small'; break;
					case 'MEDIUM': $returner = 'medium'; break;
					case 'LARGE': $returner = 'large'; break;
				}
				break;
			case 'float':
				switch($value) {
					case 'LEFT': $returner = 'full leftHalf'; break;
					case 'RIGHT': $returner = 'full rightHalf'; break;
				}
				break;
			case 'layout':
				switch($value) {
					case 'THREE_COLUMNS': $returner = 'full threeColumns'; break;
					case 'TWO_COLUMNS': $returner = 'full twoColumns'; break;
					case 'ONE_COLUMN': $returner = 'full'; break;
				}
				break;
			case 'measure':
				switch($value) {
					case '1OF2': $returner = 'size1of2'; break;
					case '2OF3': $returner = 'size2of3'; break;
					case '3OF4': $returner = 'size3of4'; break;
				}
				break;
		}

		if (!$returner) {
			trigger_error('FBV: invalid style value ['.$category.', '.$value.']');
			return '';
		}

		return $returner;
	}

	/**
	 * A form area that contains form sections. 
	 * parameters: id
	 * @param $params array
	 * @param $content string
	 * @param $smarty object
	 * @param $repeat
	 */
	function smartyFBVFormArea($params, $content, &$smarty, &$repeat) {
		if (!isset($params['id'])) {
			trigger_error('FBV: form area \'id\' not set.');
			return '';
		}

 		if (!$repeat) {
			$smarty->assign('FBV_id', $params['id']);
			$smarty->assign('FBV_content', $content);
			return $smarty->fetch('form/formArea.tpl');
		}
		return '';
	}

	/**
	 * A form section that contains controls in a variety of layout possibilities.
	 * parameters: title, float (optional), layout (optional), group (optional), required (optional), for (optinal)
	 * @param $params array
	 * @param $content string
	 * @param $smarty object
	 * @param $repeat
	 */
	function smartyFBVFormSection($params, $content, &$smarty, &$repeat) {

		if (!$repeat) {
			$smarty->assign('FBV_group', isset($params['group']) ? $params['group'] : false);
			$smarty->assign('FBV_required', isset($params['required']) ? $params['required'] : false);
			$smarty->assign('FBV_labelFor', empty($params['for']) ? null : $params['for']);

			$smarty->assign('FBV_title', $params['title']);
			$smarty->assign('FBV_content', $content);

		    
			$floatInfo = '';
			$float = isset($params['float']) ? $params['float'] : null;
			if ($float) {
				$floatInfo = $this->getStyleInfoByIdentifier('float', $float);
			}

			$layoutInfo = '';
			$layout = isset($params['layout']) ? $params['layout'] : null;
			if ($layout) {
				$layoutInfo = $this->getStyleInfoByIdentifier('layout', $layout);
			}

			$class = empty($layoutInfo) ? $floatInfo : $layoutInfo . ' ' . $floatInfo;

			if (!empty($this->formSectionErrors)) {
				$class = $class . (empty($class) ? '' : ' ') . 'error';
			}

			$smarty->assign('FBV_sectionErrors', $this->formSectionErrors);
			$smarty->assign('FBV_class', $class);

			$smarty->assign('FBV_layoutColumns', empty($layoutInfo) ? false : true);
			$this->formSectionErrors = array();

			return $smarty->fetch('form/formSection.tpl');

		} else {
			$this->formSectionErrors = array();
		}
		return '';
	}

	/**
	 * Form element.
	 * parameters: type, id, label (optional), required (optional), measure, any other attributes specific to 'type'
	 * @param $params array
	 * @param $smarty object
	 */
	function smartyFBVElement($params, &$smarty, $content = null) {
		if (isset($params['type'])) {
			switch (strtolower($params['type'])) {
				case 'text': 
					$content = $this->smartyFBVTextInput($params, $smarty);
					break;
				case 'textarea':
					$content = $this->smartyFBVTextArea($params, $smarty);
					break;
				case 'checkbox': 
					$content = $this->smartyFBVCheckbox($params, $smarty);
					unset($params['label']);
					break;
				case 'radio': 
					$content = $this->smartyFBVRadioButton($params, $smarty);
					unset($params['label']);
					break;
				case 'select':
					$content = $this->smartyFBVSelect($params, $smarty);
					break;
				case 'custom':
					break;
				default: $content = null;
			}

			if (!$content) return '';

			unset($params['type']);

			$parent = $smarty->_tag_stack[count($smarty->_tag_stack)-1];
			$group = false;

			if ($parent) {
				if (isset($this->errorFields[$params['id']])) {
					array_push($this->formSectionErrors, $this->errorsArray[$params['id']]);
				}

				if (isset($parent[1]['group']) && $parent[1]['group']) {
					$group = true;
				}
			}

			$smarty->assign('FBV_content', $content);
			$smarty->assign('FBV_group', $group);
			$smarty->assign('FBV_id', isset($params['id']) ? $params['id'] : null);
			$smarty->assign('FBV_label', empty($params['label']) ? null : $params['label']);
			$smarty->assign('FBV_required', isset($params['required']) ? $params['required'] : false);
			$smarty->assign('FBV_measureInfo', empty($params['measure']) ? null : $this->getStyleInfoByIdentifier('measure', $params['measure']));

			return $smarty->fetch('form/element.tpl');
		}
		return '';
	}

	/**
	 * Custom form element. User form code is placed between customElement tags.
	 * parameters: id, label (optional), required (optional)
	 * @param $params array
	 * @param $content string
	 * @param $smarty object
	 * @param $repeat
	 */
	function smartyFBVCustomElement($params, $content, &$smarty, &$repeat) {
		if (!$repeat) {
			$params['type'] = 'custom';
			return $this->smartyFBVElement($params, $smarty, $content);
		}
		return '';
	}

	/**
	 * Form button.
	 * parameters: label (or value), disabled (optional), type (optional), all other attributes associated with this control (except class)
	 * @param $params array
	 * @param $smarty object
	 */
	function smartyFBVButton($params, &$smarty) {
		$buttonParams = '';
      
		// accept 'value' param, but the 'label' param is preferred
		if (isset($params['value'])) {
			$value = $params['value'];
			$params['label'] = isset($params['label']) ? $params['label'] : $value;
			unset($params['value']);
		}

		// the type of this button. the default value is 'button'
		$params['type'] = isset($params['type']) ? strtolower($params['type']) : 'button';
		$params['disabled'] = isset($params['disabled']) ? $params['disabled'] : false;

		foreach ($params as $key => $value) {
			switch ($key) {
				case 'label': $smarty->assign('FBV_label', $value); break;
				case 'type': $smarty->assign('FBV_type', $value); break;
				case 'class': break; //ignore class attributes
				case 'disabled': $smarty->assign('FBV_disabled', $params['disabled']); break;
				default: $buttonParams .= htmlspecialchars($key, ENT_QUOTES, LOCALE_ENCODING) . '="' . htmlspecialchars($value, ENT_QUOTES, LOCALE_ENCODING) . '" ';
			}
		}

		$smarty->assign('FBV_buttonParams', $buttonParams);

		return $smarty->fetch('form/button.tpl');
	}    

	/**
	 * Form text input.
	 * parameters: size, disabled (optional), name (optional - assigned value of 'id' by default), all other attributes associated with this control (except class and type)
	 * @param $params array
	 * @param $smarty object
	 */
	function smartyFBVTextInput($params, &$smarty) {
		if (!isset($params['id'])) {
			trigger_error('FBV: text input form element \'id\' not set.');
			return '';
		}

		$textInputParams = '';

		$params['name'] = isset($params['name']) ? $params['name'] : $params['id'];
		$params['disabled'] = isset($params['disabled']) ? $params['disabled'] : false;

		$smarty->assign('FBV_isPassword', isset($params['password']) ? true : false);

		// prepare the control's size info
		if (isset($params['size'])) {
			$sizeInfo = $this->getStyleInfoByIdentifier('size', $params['size']);
			$smarty->assign('FBV_sizeInfo', $sizeInfo);
			unset($params['size']);
		} else {
			$smarty->assign('FBV_sizeInfo', null);
		}

		foreach ($params as $key => $value) {
			switch ($key) {
				case 'class': break; //ignore class attributes
				case 'label': break;
				case 'type': break;
				case 'disabled': $smarty->assign('FBV_disabled', $params['disabled']); break;
				default: $textInputParams .= htmlspecialchars($key, ENT_QUOTES, LOCALE_ENCODING) . '="' . htmlspecialchars($value, ENT_QUOTES, LOCALE_ENCODING). '" ';
			}
		}

		$smarty->assign('FBV_textInputParams', $textInputParams);

		return $smarty->fetch('form/textInput.tpl');
	}

	/**
	 * Form text area.
	 * parameters: value, id, name (optional - assigned value of 'id' by default), disabled (optional), all other attributes associated with this control (except class)
	 * @param $params array
	 * @param $smarty object
	 */
	function smartyFBVTextArea($params, &$smarty) {
		if (!isset($params['id'])) {
			trigger_error('FBV: text area form element \'id\' not set.');
			return '';
		}

		$textAreaParams = '';
		$params['name'] = isset($params['name']) ? $params['name'] : $params['id'];
		$params['disabled'] = isset($params['disabled']) ? $params['disabled'] : false;

		// prepare the control's size info
		if (isset($params['size'])) {
			$sizeInfo = $this->getStyleInfoByIdentifier('size', $params['size']);
			$smarty->assign('FBV_sizeInfo', $sizeInfo);
			unset($params['size']);
		} else {
			$smarty->assign('FBV_sizeInfo', null);
		}

		foreach ($params as $key => $value) {
			switch ($key) {
				case 'value': $smarty->assign('FBV_value', $value); break;
				case 'label': break;
				case 'type': break;
				case 'class': break; //ignore class attributes
				case 'disabled': $smarty->assign('FBV_disabled', $params['disabled']); break;
				default: $textAreaParams .= htmlspecialchars($key, ENT_QUOTES, LOCALE_ENCODING) . '="' . htmlspecialchars($value, ENT_QUOTES, LOCALE_ENCODING) . '" ';
			}
		}

		$smarty->assign('FBV_textAreaParams', $textAreaParams);

		return $smarty->fetch('form/textarea.tpl');
	}

	/**
	 * Form select control.
	 * parameters: from [array], selected [array index], defaultLabel (optional), defaultValue (optional), disabled (optional), 
	 * 	translate (optional), name (optional - value of 'id' by default), all other attributes associated with this control (except class)
	 * @param $params array
	 * @param $smarty object
	 */
	function smartyFBVSelect($params, &$smarty) {
		if (!isset($params['id'])) {
			trigger_error('FBV: select form element \'id\' not set.');
			return '';
		}

		$selectParams = '';
		$params['name'] = isset($params['name']) ? $params['name'] : $params['id'];
		$params['translate'] = isset($params['translate']) ? $params['translate'] : true;
		$params['disabled'] = isset($params['disabled']) ? $params['disabled'] : false;

		if (!$params['defaultValue'] || !$params['defaultLabel']) {
			if (isset($params['defaultValue'])) unset($params['defaultValue']);
			if (isset($params['defaultLabel'])) unset($params['defaultLabel']);
			$smarty->assign('FBV_defaultValue', null);
			$smarty->assign('FBV_defaultLabel', null);
		}

		foreach ($params as $key => $value) {
			switch ($key) {
				case 'from': $smarty->assign('FBV_from', $value); break;
				case 'selected': $smarty->assign('FBV_selected', $value); break;
				case 'translate': $smarty->assign('FBV_translate', $value); break;
				case 'defaultValue': $smarty->assign('FBV_defaultValue', $value); break;
				case 'defaultLabel': $smarty->assign('FBV_defaultLabel', $value); break;
				case 'class': break; //ignore class attributes
				case 'disabled': $smarty->assign('FBV_disabled', $params['disabled']); break;
				default: $selectParams .= htmlspecialchars($key, ENT_QUOTES, LOCALE_ENCODING) . '="' . htmlspecialchars($value, ENT_QUOTES, LOCALE_ENCODING) . '" ';
			}
		}

		$smarty->assign('FBV_selectParams', $selectParams);

		return $smarty->fetch('form/select.tpl');
	}

	/**
	 * Checkbox input control.
	 * parameters: label, disabled (optional), translate (optional), name (optional - value of 'id' by default), all other attributes associated with this control (except class and type)
	 * @param $params array
	 * @param $smarty object
	 */
	function smartyFBVCheckbox($params, &$smarty) {
		if (!isset($params['id'])) {
			trigger_error('FBV: checkbox form element \'id\' not set.');
			return '';
		}

		$checkboxParams = '';
		$params['name'] = isset($params['name']) ? $params['name'] : $params['id'];
		$params['translate'] = isset($params['translate']) ? $params['translate'] : true;
		$params['checked'] = isset($params['checked']) ? $params['checked'] : false;
		$params['disabled'] = isset($params['disabled']) ? $params['disabled'] : false;

		foreach ($params as $key => $value) {
			switch ($key) {
				case 'class': break; //ignore class attributes
				case 'type': break;
				case 'id': $smarty->assign('FBV_id', $params['id']); break;
				case 'label': $smarty->assign('FBV_label', $params['label']); break;
				case 'translate': $smarty->assign('FBV_translate', $params['translate']); break;
				case 'checked': $smarty->assign('FBV_checked', $params['checked']); break;
				case 'disabled': $smarty->assign('FBV_disabled', $params['disabled']); break;
				default: $checkboxParams .= htmlspecialchars($key, ENT_QUOTES, LOCALE_ENCODING) . '="' . htmlspecialchars($value, ENT_QUOTES, LOCALE_ENCODING) . '" ';
			}
		}

		$smarty->assign('FBV_checkboxParams', $checkboxParams);

		return $smarty->fetch('form/checkbox.tpl');
	}

	/**
	 * Radio input control.
	 * parameters: label, disabled (optional), translate (optional), name (optional - value of 'id' by default), all other attributes associated with this control (except class and type)
	 * @param $params array
	 * @param $smarty object
	 */
	function smartyFBVRadioButton($params, &$smarty) {
		if (!isset($params['id'])) {
			trigger_error('FBV: radio input form element \'id\' not set.');
			return '';
		}

		$radioParams = '';
		$params['name'] = isset($params['name']) ? $params['name'] : $params['id'];
		$params['translate'] = isset($params['translate']) ? $params['translate'] : true;
		$params['checked'] = isset($params['checked']) ? $params['checked'] : false;
		$params['disabled'] = isset($params['disabled']) ? $params['disabled'] : false;

		foreach ($params as $key => $value) {
			switch ($key) {
				case 'class': break; //ignore class attributes
				case 'type': break;
				case 'id': $smarty->assign('FBV_id', $params['id']); break;
				case 'label': $smarty->assign('FBV_label', $params['label']); break;
				case 'translate': $smarty->assign('FBV_translate', $params['translate']); break;
				case 'checked': $smarty->assign('FBV_checked', $params['checked']); break;
				case 'disabled': $smarty->assign('FBV_disabled', $params['disabled']); break;
				default: $radioParams .= htmlspecialchars($key, ENT_QUOTES, LOCALE_ENCODING) . '="' . htmlspecialchars($value, ENT_QUOTES, LOCALE_ENCODING) . '" ';
			}
		}

		$smarty->assign('FBV_radioParams', $radioParams);

		return $smarty->fetch('form/radioButton.tpl');
	}
}

?>