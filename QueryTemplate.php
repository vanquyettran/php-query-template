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
    // {% getName(123) %}
    const __FUNC_OPEN = '{%';
    const __FUNC_CLOSE = '%}';
    // " My name is [% getName(123) %] "
    const __EMBED_FUNC_OPEN = '[%';
    const __EMBED_FUNC_CLOSE = '%]';

    // {$ my_name $}
    const __VAR_OPEN = '{$';
    const __VAR_CLOSE = '$}';
    // " My name is [$ my_name $] "
    const __EMBED_VAR_OPEN = '[$';
    const __EMBED_VAR_CLOSE = '$]';

    // (` my_name : getName(123) `)
    // (` country : "Vietnam" `)
    // (` year : 1993 `)
    const __ASSIGNMENT_OPEN = '(`';
    const __ASSIGNMENT_CLOSE = '`)';
    const __ASSIGNMENT_OPERATOR = ':';

    // {% findStudent(123).#getInfo("name") %}
    const __OBJECT_OPERATOR = '.#';
    // " [% findStudent(123).@getInfo("name") %] "
    const __EMBED_OBJECT_OPERATOR = '.@';

    /**
     * @var string
     */
    public $templateMethodCaller = 'callTemplateMethod';

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
                return $this->getVariableValue($varName);
            }
        ]);
    }

    /**
     * @inheritdoc
     */
    public function run()
    {
        parent::run();

        $this->assignValueForVariables();
        $this->findAndEchoVariables();
        $this->findAndReplaceBlocks();

        return $this->content;
    }

    /**
     * @return string as new content after replacing all template groups
     */
    protected function findAndReplaceBlocks()
    {
        // Find all template blocks
        $block_matches = $this->findGroups(self::__FUNC_OPEN, self::__FUNC_CLOSE, $this->content);

        // Replace each template block by computed text
        foreach ($block_matches[1] as $block) {
            $this->_tmpErrors = [];
            $object = $this->execFunctionAndObjectMethods($block);
            // Output text (with errors message)
            $text = $this->objectToString($object) . $this->getTmpErrorsMessage();
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

    protected function findAndEchoVariables()
    {
        // Find all template blocks
        $block_matches = $this->findGroups(self::__VAR_OPEN, self::__VAR_CLOSE, $this->content);

        // Replace each template block by computed text
        foreach ($block_matches[1] as $block) {
            $this->_tmpErrors = [];
            $object = $this->getVariableValue($block);
            // Output text (with errors message)
            $text = $this->objectToString($object) . $this->getTmpErrorsMessage();
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

    protected function assignValueForVariables()
    {
        // Find all template blocks
        $block_matches = $this->findGroups(self::__ASSIGNMENT_OPEN, self::__ASSIGNMENT_CLOSE, $this->content);

        // Replace each template block by computed text
        foreach ($block_matches[1] as $block) {
            $this->_tmpErrors = [];
            $arr = explode(self::__ASSIGNMENT_OPERATOR, $block);
            $arrLength = count($arr);
            if ($arrLength < 2
                || ($varName = trim(array_shift($arr))) == ''
                || ($input = trim(implode(self::__ASSIGNMENT_OPERATOR, $arr))) == ''
            ) {
                $this->_tmpErrors[] = $this->variableNameOrValueDoesNotProvided();
            } else {
                $value = json_decode($input);
                if (json_last_error() != JSON_ERROR_NONE) {
                    $value = $this->execFunctionAndObjectMethods($input);
                }
                $this->variables[$varName] = $value;
            }

            $this->content = str_replace(
                self::__ASSIGNMENT_OPEN . $block . self::__ASSIGNMENT_CLOSE,
                $this->getTmpErrorsMessage(),
                $this->content
            );
            if (!empty($this->_tmpErrors)) {
                self::$errors[] = $this->_tmpErrors;
            }
        }

    }

    protected function execFunc($fn)
    {
        $object = null;
        list($fnName, $fnArgs) = $this->getFunctionNameAndArguments($fn);
        if (!isset($this->queries[$fnName])) {
            $this->_tmpErrors[] = $this->functionDoesNotExistError($fnName);
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

    protected function execMethod($object, $fn)
    {
        $newObject = null;
        list($fnName, $fnArgs) = $this->getFunctionNameAndArguments($fn);

        if (!is_object($object)) {
            $this->_tmpErrors[] = $this->cannotGetMethodOfNonObjectError($fnName);
            return $newObject;
        }

        if (!method_exists($object, $this->templateMethodCaller)) {
            $this->_tmpErrors[] = $this->methodDoesNotExistError(
                get_class($object), $this->templateMethodCaller);
            return $newObject;
        }



        // Execute method of this object
        try {
            $fnArgs = $this->findAndReplaceEmbeddedMethods($object, $fnArgs);
            $newObject = call_user_func_array([$object, $this->templateMethodCaller], [$fnName, $fnArgs]);
        } catch (\Exception $e) {
            $this->_tmpErrors[] = $e->getMessage();
        }

        return $newObject;
    }

    protected function execFunctionAndObjectMethods($str)
    {
        $fns = explode(self::__OBJECT_OPERATOR, $str);
        $object = null;
        foreach ($fns as $i => $fn) {
            $errNum0 = count($this->_tmpErrors);
            if ($i == 0) {
                $object = $this->execFunc($fn);
            } else {
                $object = $this->execMethod($object, $fn);
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
    protected function embeddedMethodToText($owner, $str)
    {
        $fns = explode(self::__EMBED_OBJECT_OPERATOR, $str);
        $object = null;
        foreach ($fns as $i => $fn) {
            $errNum0 = count($this->_tmpErrors);
            if ($i == 0 && 'this' == trim($fn)) {
                $object = $owner;
            } else {
                if ($i == 0) {
                    $object = $this->execFunc($fn);
                } else {
                    $object = $this->execMethod($object, $fn);
                }
            }
            if (count($this->_tmpErrors) > $errNum0) {
                break;
            }
        }

        return $object;
    }

    protected function objectToString($object)
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
    protected function findAndReplaceEmbeddedMethods($owner, $input)
    {
        if (is_array($input)) {
            foreach ($input as &$item) {
                $item = $this->findAndReplaceEmbeddedMethods($owner, $item);
            }
        } else if (is_string($input)) {
            // Find all embedded methods
            $embed_matches = $this->findGroups(self::__EMBED_FUNC_OPEN, self::__EMBED_FUNC_CLOSE, $input);

            // Replace each template embed by computed text
            foreach ($embed_matches[1] as $embeddedMethod) {
                $embeddedText = $this->embeddedMethodToText($owner, $embeddedMethod);
                $input = str_replace(self::__EMBED_FUNC_OPEN . $embeddedMethod . self::__EMBED_FUNC_CLOSE, $embeddedText, $input);
            }
        }

        return $input;
    }

    protected function findAndReplaceEmbeddedVariables($input)
    {
        if (is_array($input)) {
            foreach ($input as &$item) {
                $item = $this->findAndReplaceEmbeddedVariables($item);
            }
        } else if (is_string($input)) {
            // Find all embedded methods
            $embed_matches = $this->findGroups(self::__EMBED_VAR_OPEN, self::__EMBED_VAR_CLOSE, $input);

            // Replace each template embed by computed text
            foreach ($embed_matches[1] as $embeddedMethod) {
                $embeddedText = $this->objectToString($this->getVariableValue($embeddedMethod));
                $input = str_replace(
                    self::__EMBED_VAR_OPEN . $embeddedMethod . self::__EMBED_VAR_CLOSE,
                    $embeddedText,
                    $input
                );
            }
        }

        return $input;
    }

    protected function getVariableValue($varName)
    {
        try {
            $val = $this->variables[trim($varName)];
        } catch (\Exception $e) {
            $val = '';
            $this->_tmpErrors[] = $e->getMessage();
        }
        return $val;
    }


    protected function findGroups($open, $close, $input)
    {
        $open = preg_quote($open, '/');
        $close = preg_quote($close, '/');
        preg_match_all(
            "/$open((?:(?!$open)(?!$close)[\s\S])*)$close/",
            $input,
            $matches
        );
        return $matches;
    }

    /**
     * @param $str
     * @return array
     */
    protected function getFunctionNameAndArguments($str)
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

        $fnArgs = $this->findAndReplaceEmbeddedVariables($fnArgs);

        return [$fnName, $fnArgs];
    }

    /**
     * @return string
     */
    protected function getTmpErrorsMessage()
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
    protected function functionDoesNotExistError($fnName)
    {
        return "Function \"$fnName\" does not exist.";
    }

    /**
     * @param $objName
     * @param $fnName
     * @return string
     */
    protected function methodDoesNotExistError($objName, $fnName)
    {
        return "Object \"$objName\" does not have the method \"$fnName\".";
    }

    /**
     * @param $fnName
     * @return string
     */
    protected function cannotGetMethodOfNonObjectError($fnName)
    {
        return "Cannot get method \"$fnName\" of non-object.";
    }

    protected function variableNameOrValueDoesNotProvided()
    {
        return "Variable name or value does not provided for assignment.";
    }

}