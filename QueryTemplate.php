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
    const __BLOCK_OPEN = '{{';
    const __BLOCK_CLOSE = '}}';
    const __ASSIGNMENT_OPEN = '[[';
    const __ASSIGNMENT_CLOSE = ']]';
    const __METHOD_OPEN = '<%';
    const __METHOD_CLOSE = '%>';
    const __VARIABLE_OPEN = '<?';
    const __VARIABLE_CLOSE = '?>';
    const __ASSIGNMENT_OPERATOR = ':';
    const __OBJECT_OPERATOR = '~';

    /**
     * @var string $content to store input text content
     *
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
     * @var array $errors
     */
    public $errors;

    /**
     * @var array $_tmpErrors
     */
    private $_tmpErrors;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

    }

    /**
     * @inheritdoc
     */
    public function run()
    {
        parent::run();

        $this->_assignValueForVariables();
        return $this->_findAndReplaceBlocks();
    }

    /**
     * @return string as new content after replacing all template groups
     */
    protected function _findAndReplaceBlocks()
    {
        // Find all template blocks
        preg_match_all(
            "/" . preg_quote(self::__BLOCK_OPEN) . "(.*?)" . preg_quote(self::__BLOCK_CLOSE) . "/s",
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
                self::__BLOCK_OPEN . $block . self::__BLOCK_CLOSE,
                $text,
                $this->content
            );
        }

        return $this->content;
    }

    protected function _assignValueForVariables()
    {
        // Find all template blocks
        preg_match_all(
            "/" . preg_quote(self::__ASSIGNMENT_OPEN) . "(.*?)" . preg_quote(self::__ASSIGNMENT_CLOSE) . "/s",
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
            || ($fnStr = trim(implode(self::__ASSIGNMENT_OPERATOR, $arr))) == ''
            ) {
                $this->_tmpErrors[] = $this->_variableNameOrValueDoesNotProvided();
            } else {
                $this->variables[$varName] = $this->_execFunctionAndObjectMethods($fnStr);
            }

            $this->content = str_replace(
                self::__ASSIGNMENT_OPEN . $block . self::__ASSIGNMENT_CLOSE,
                $this->_getTmpErrorsMessage(),
                $this->content
            );
        }

    }

    protected function _execFunctionAndObjectMethods($str)
    {
        $fns = explode(self::__OBJECT_OPERATOR, $str);
        $object = null;
        foreach ($fns as $i => $fn) {
            list($fnName, $fnArgs) = $this->_getFunctionNameAndArguments($fn);
            if ($i == 0) {
                if (!isset($this->queries[$fnName])) {
                    $this->_tmpErrors[] = $this->_functionDoesNotExistError($fnName);
                    break;
                }

                // Get object via static/first function
                try {
                    $func = $this->queries[$fnName];
                    $object = $func(...$fnArgs);
                } catch (\Exception $e) {
                    $this->_tmpErrors[] = $e->getMessage();
                    break;
                }
            } else {
                if (!is_object($object)) {
                    $this->_tmpErrors[] = $this->_cannotGetMethodOfNonObjectError($fnName);
                    break;
                }

                if (!method_exists($object, $fnName)) {
                    $this->_tmpErrors[] = $this->_methodDoesNotExistError(get_class($object), $fnName);
                    break;
                }

                // Execute method of this object
                try {
                    $fnArgs = $this->_findAndReplaceEmbeddedMethods($object, $fnArgs);
                    $object = call_user_func_array([$object, $fnName], $fnArgs);
                } catch (\Exception $e) {
                    $this->_tmpErrors[] = $e->getMessage();
                    break;
                }
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
                "/" . preg_quote(self::__METHOD_OPEN) . "(.*?)" . preg_quote(self::__METHOD_CLOSE) . "/s",
                $input,
                $embed_matches
            );

            // Replace each template embed by computed text
            foreach ($embed_matches[1] as $embeddedMethod) {
                $embeddedText = $this->_embeddedMethodToText($owner, $embeddedMethod);
                $input = str_replace(self::__METHOD_OPEN . $embeddedMethod . self::__METHOD_CLOSE, $embeddedText, $input);
            }
        }

        return $input;
    }

    /**
     * @param $owner
     * @param $embeddedMethod
     * @return mixed
     */
    protected function _embeddedMethodToText($owner, $embeddedMethod)
    {
        list($fnName, $fnArgs) = $this->_getFunctionNameAndArguments($embeddedMethod);
        return call_user_func_array([$owner, $fnName], $fnArgs);
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
                "/" . preg_quote(self::__VARIABLE_OPEN) . "(.*?)" . preg_quote(self::__VARIABLE_CLOSE) . "/s",
                $input,
                $embed_matches
            );

            // Replace each template embed by computed text
            foreach ($embed_matches[1] as $embeddedMethod) {
                try {
                    $val = $this->variables[trim($embeddedMethod)];
                } catch (\Exception $e) {
                    $val = '';
                    $this->_tmpErrors[] = $e->getMessage();
                }
                $embeddedText = $this->_objectToString($val);
                $input = str_replace(self::__VARIABLE_OPEN . $embeddedMethod . self::__VARIABLE_CLOSE,
                    $embeddedText, $input);
            }
        }

        return $input;
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