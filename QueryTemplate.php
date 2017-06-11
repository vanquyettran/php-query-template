<?php
/**
 * Created by PhpStorm.
 * User: User
 * Date: 4/27/2017
 * Time: 2:56 AM
 */

namespace vanquyet\queryTemplate;

use yii\base\Widget;

class QueryTemplate extends Widget
{
    const __FUNC_OPEN = '<tmpl:func>';
    const __FUNC_CLOSE = '</tmpl:func>';
    const __EMBED_FUNC_OPEN = '<tmpl:embFunc>';
    const __EMBED_FUNC_CLOSE = '</tmpl:embFunc>';

    const __VAR_OPEN = '<tmpl:var>';
    const __VAR_CLOSE = '</tmpl:var>';
    const __EMBED_VAR_OPEN = '<tmpl:embVar>';
    const __EMBED_VAR_CLOSE = '</tmpl:embVar>';

    const __ASSIGNMENT_OPEN = '<tmpl:assign>';
    const __ASSIGNMENT_CLOSE = '</tmpl:assign>';
    const __ASSIGNMENT_OPERATOR = '|';

    const __OBJECT_OPERATOR = '.#';
    const __EMBED_OBJECT_OPERATOR = '.@';

    /**
     * @var string
     */
    public $callTemplateMethod_FuncName = 'callTemplateMethod';

    /**
     * @var string $content to store input text content
     */
    public $content;

    /**
     * @var array $queries contains function list to get object
     */
    public $queries;

    /**
     * @var array $variables
     */
    public $variables;

    /**
     * @var array $_tmpErrors
     */
    private $_tmpErrors;

    /**
     * @var array $errors
     */
    public static $errors;


    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        self::$errors = [];

        $this->queries = array_merge($this->queries, [
            '$' => function ($varName) {
                return $this->_getVariableValue($varName);
            }
        ]);
    }

    /**
     * @inheritdoc
     */
    public function run()
    {
        parent::run();

        $this->_assignValueForVariables();
        $this->_findAndEchoVariables();
        $this->_findAndReplaceBlocks();

        return $this->content;
    }

    /**
     * @return string as new content after replacing all template groups
     */
    protected function _findAndReplaceBlocks()
    {
        // Find all template blocks
        preg_match_all(
            "/" . preg_quote(self::__FUNC_OPEN, '/') . "(.*?)" . preg_quote(self::__FUNC_CLOSE, '/') . "/s",
            $this->content,
            $block_matches
        );

        // Replace each template block by computed text
        foreach ($block_matches[1] as $block) {
            $this->_tmpErrors = [];
            $object = $this->_execFunctionAndObjectMethods($block);
            // Output text (with errors message)
            $text = $this->_objectToString($object) . $this->_getTmpErrorsMessage();
            $this->content = str_replace(
                self::__FUNC_OPEN . $block . self::__FUNC_CLOSE,
                $text,
                $this->content
            );
            if (!empty($this->_tmpErrors)) {
                self::$errors[] = $this->_tmpErrors;
            }
        }

        return $this->content;
    }

    protected function _findAndEchoVariables()
    {
        // Find all template blocks
        preg_match_all(
            "/" . preg_quote(self::__VAR_OPEN, '/') . "(.*?)" . preg_quote(self::__VAR_CLOSE, '/') . "/s",
            $this->content,
            $block_matches
        );

        // Replace each template block by computed text
        foreach ($block_matches[1] as $block) {
            $this->_tmpErrors = [];
            $object = $this->_getVariableValue($block);
            // Output text (with errors message)
            $text = $this->_objectToString($object) . $this->_getTmpErrorsMessage();
            $this->content = str_replace(
                self::__VAR_OPEN . $block . self::__VAR_CLOSE,
                $text,
                $this->content
            );
            if (!empty($this->_tmpErrors)) {
                self::$errors[] = $this->_tmpErrors;
            }
        }

        return $this->content;
    }

    protected function _assignValueForVariables()
    {
        // Find all template blocks
        preg_match_all(
            "/" . preg_quote(self::__ASSIGNMENT_OPEN, '/') . "(.*?)" . preg_quote(self::__ASSIGNMENT_CLOSE, '/') . "/s",
            $this->content,
            $block_matches
        );

        // Replace each template block by computed text
        foreach ($block_matches[1] as $block) {
            $this->_tmpErrors = [];
            $arr = explode(self::__ASSIGNMENT_OPERATOR, $block);
            $arrLength = count($arr);
            if ($arrLength < 2
                || ($varName = trim(array_shift($arr))) == ''
                || ($input = trim(implode(self::__ASSIGNMENT_OPERATOR, $arr))) == ''
            ) {
                $this->_tmpErrors[] = $this->_variableNameOrValueDoesNotProvided();
            } else {
                $value = json_decode($input);
                if (json_last_error() != JSON_ERROR_NONE) {
                    $value = $this->_execFunctionAndObjectMethods($input);
                }
                $this->variables[$varName] = $value;
            }

            $this->content = str_replace(
                self::__ASSIGNMENT_OPEN . $block . self::__ASSIGNMENT_CLOSE,
                $this->_getTmpErrorsMessage(),
                $this->content
            );
            if (!empty($this->_tmpErrors)) {
                self::$errors[] = $this->_tmpErrors;
            }
        }

    }

    protected function _execFunc($fn)
    {
        $object = null;
        list($fnName, $fnArgs) = $this->_getFunctionNameAndArguments($fn);
        if (!isset($this->queries[$fnName])) {
            $this->_tmpErrors[] = $this->_functionDoesNotExistError($fnName);
            return $object;
        }

        // Get object via static/first function
        try {
            $func = $this->queries[$fnName];
//            $object = $func(...$fnArgs);
            $object = call_user_func_array($func, $fnArgs);
        } catch (\Exception $e) {
            $this->_tmpErrors[] = $e->getMessage();
        }

        return $object;
    }

    protected function _execMethod($object, $fn)
    {
        $newObject = null;
        list($fnName, $fnArgs) = $this->_getFunctionNameAndArguments($fn);

        if (!is_object($object)) {
            $this->_tmpErrors[] = $this->_cannotGetMethodOfNonObjectError($fnName);
            return $newObject;
        }

        if (!method_exists($object, $this->callTemplateMethod_FuncName)) {
            $this->_tmpErrors[] = $this->_methodDoesNotExistError(get_class($object), $this->callTemplateMethod_FuncName);
            return $newObject;
        }



        // Execute method of this object
        try {
            $fnArgs = $this->_findAndReplaceEmbeddedMethods($object, $fnArgs);
            $newObject = call_user_func_array([$object, $this->callTemplateMethod_FuncName], [$fnName, $fnArgs]);
        } catch (\Exception $e) {
            $this->_tmpErrors[] = $e->getMessage();
        }

        return $newObject;
    }

    protected function _execFunctionAndObjectMethods($str)
    {
        $fns = explode(self::__OBJECT_OPERATOR, $str);
        $object = null;
        foreach ($fns as $i => $fn) {
            $errNum0 = count($this->_tmpErrors);
            if ($i == 0) {
                $object = $this->_execFunc($fn);
            } else {
                $object = $this->_execMethod($object, $fn);
            }
            if (count($this->_tmpErrors) > $errNum0) {
                break;
            }
        }

        return $object;
    }

    /**
     * @param $owner
     * @param $str
     * @return mixed|null
     */
    protected function _embeddedMethodToText($owner, $str)
    {
        $fns = explode(self::__EMBED_OBJECT_OPERATOR, $str);
        $object = null;
        foreach ($fns as $i => $fn) {
            $errNum0 = count($this->_tmpErrors);
            if ($i == 0 && 'this' == trim($fn)) {
                $object = $owner;
            } else {
                if ($i == 0) {
                    $object = $this->_execFunc($fn);
                } else {
                    $object = $this->_execMethod($object, $fn);
                }
            }
            if (count($this->_tmpErrors) > $errNum0) {
                break;
            }
        }

        return $object;
    }

    protected function _objectToString($object)
    {
        $text = '';

        try {
            $text = (string) $object;
        } catch (\Exception $e) {
            $this->_tmpErrors[] = $e->getMessage();
        }

        return $text;
    }

    /**
     * @param $owner
     * @param $input
     * @return mixed
     */
    protected function _findAndReplaceEmbeddedMethods($owner, $input)
    {
        if (is_array($input)) {
            foreach ($input as &$item) {
                $item = $this->_findAndReplaceEmbeddedMethods($owner, $item);
            }
        } else if (is_string($input)) {
            // Find all embedded methods
            preg_match_all(
                "/" . preg_quote(self::__EMBED_FUNC_OPEN, '/') . "(.*?)" . preg_quote(self::__EMBED_FUNC_CLOSE, '/') . "/s",
                $input,
                $embed_matches
            );

            // Replace each template embed by computed text
            foreach ($embed_matches[1] as $embeddedMethod) {
                $embeddedText = $this->_embeddedMethodToText($owner, $embeddedMethod);
                $input = str_replace(self::__EMBED_FUNC_OPEN . $embeddedMethod . self::__EMBED_FUNC_CLOSE, $embeddedText, $input);
            }
        }

        return $input;
    }

    protected function _findAndReplaceEmbeddedVariables($input)
    {
        if (is_array($input)) {
            foreach ($input as &$item) {
                $item = $this->_findAndReplaceEmbeddedVariables($item);
            }
        } else if (is_string($input)) {
            // Find all embedded methods
            preg_match_all(
                "/" . preg_quote(self::__EMBED_VAR_OPEN, '/') . "(.*?)" . preg_quote(self::__EMBED_VAR_CLOSE, '/') . "/s",
                $input,
                $embed_matches
            );

            // Replace each template embed by computed text
            foreach ($embed_matches[1] as $embeddedMethod) {
                $embeddedText = $this->_objectToString($this->_getVariableValue($embeddedMethod));
                $input = str_replace(
                    self::__EMBED_VAR_OPEN . $embeddedMethod . self::__EMBED_VAR_CLOSE,
                    $embeddedText,
                    $input
                );
            }
        }

        return $input;
    }

    protected function _getVariableValue($varName)
    {
        try {
            $val = $this->variables[trim($varName)];
        } catch (\Exception $e) {
            $val = '';
            $this->_tmpErrors[] = $e->getMessage();
        }
        return $val;
    }

//    protected function _embeddedVariableToText()
//    {
//
//    }

    /**
     * @param $str
     * @return array
     */
    protected function _getFunctionNameAndArguments($str)
    {

        $fnName = $str;
        $fnArgs = [];
        preg_match_all(
            "/\((.*)\)/s",
            $fnName,
            $args_matches
        );

        if (isset($args_matches[1][0])) {
            $args = $args_matches[1][0];
            $fnName = trim(str_replace("($args)", '', $fnName));
            $fnArgs = json_decode(
                "[$args]", // arguments array in json
                true // $assoc TRUE to cast {} => [] for all arguments
            );
        }

        $fnArgs = $this->_findAndReplaceEmbeddedVariables($fnArgs);

        return [$fnName, $fnArgs];
    }

    /**
     * @return string
     */
    protected function _getTmpErrorsMessage()
    {
        $errors_num = count($this->_tmpErrors);
        return $errors_num == 0 ? '' :
            "\n<!-- " . $errors_num . " error(s):\n\t"
            . implode("\n\t", $this->_tmpErrors) . "\n -->\n";
    }

    /**
     * @param $fnName
     * @return string
     */
    protected function _functionDoesNotExistError($fnName)
    {
        return "Function \"$fnName\" does not exist.";
    }

    /**
     * @param $objName
     * @param $fnName
     * @return string
     */
    protected function _methodDoesNotExistError($objName, $fnName)
    {
        return "Object \"$objName\" does not have the method \"$fnName\".";
    }

    /**
     * @param $fnName
     * @return string
     */
    protected function _cannotGetMethodOfNonObjectError($fnName)
    {
        return "Cannot get method \"$fnName\" of non-object.";
    }

    protected function _variableNameOrValueDoesNotProvided()
    {
        return "Variable name or value does not provided for assignment.";
    }

}