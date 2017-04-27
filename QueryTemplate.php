<?php
/**
 * Created by PhpStorm.
 * User: User
 * Date: 4/27/2017
 * Time: 2:56 AM
 */

namespace vanquyet\queryTemplate;


use yii\base\Exception;
use yii\base\Widget;

class QueryTemplate extends Widget
{
    const __BLOCK_OPEN = '{{';
    const __BLOCK_CLOSE = '}}';
    const __EMBED_OPEN = '[[';
    const __EMBED_CLOSE = ']]';

    /**
     * @var string $content to store input text content
     *
     */
    public $content;

    /**
     * @var array $functions
     */
    public $funcList;

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

        $newContent = $this->content;

        // Replace each template block by computed text
        foreach ($block_matches[0] as $block) {
            $text = $this->_blockToText($block);
            $newContent = str_replace($block, $text, $newContent);
        }

        return $newContent;
    }

    /**
     * @return string $text from template block
     */
    protected function _blockToText($block)
    {
        $object = null;
        $errors = [];

        $inner = substr($block, strlen(self::__BLOCK_OPEN), - strlen(self::__BLOCK_CLOSE));
        $fns = explode('.', $inner);

        foreach ($fns as $i => $fn) {
            $fnName = $fn;
            $fnArgs = [];
            preg_match_all("/\((.*?)\)/s", $fn, $args_matches);

            $args_matches_length = count($args_matches[1]);
            if ($args_matches_length > 0) {
                $args = $args_matches[1][$args_matches_length - 1];
                $fnName = trim(str_replace("($args)", '', $fnName));
                $fnArgs = json_decode(
                    "[$args]", // arguments array in json
                    true // $assoc TRUE to cast {} => [] for all arguments
                );
            }

            if ($i == 0) {
                if (!isset($this->funcList[$fnName])) {
                    $errors[] = $this->_functionDoesNotExistError($fnName);
                    break;
                }

                // Get object via static/first function
                try {
                    $func = $this->funcList[$fnName];
                    $object = $func(...$fnArgs);
                } catch (\Exception $e) {
                    $errors[] = $e->getMessage();
                    break;
                }
            } else {
                if (!is_object($object)) {
                    $errors[] = $this->_cannotGetMethodOfNonObject($fnName);
                    break;
                }

                if (!method_exists($object, $fnName)) {
                    $errors[] = $this->_methodDoesNotExistError(get_class($object), $fnName);
                    break;
                }

                // Execute method of this object
                try {
                    foreach ($fnArgs as &$arg) {
                        $arg = $this->_findAndReplaceEmbeddedMethods($object, $arg);
                    }
                    $object = call_user_func_array([$object, $fnName], $fnArgs);
                } catch (\Exception $e) {
                    $errors[] = $e->getMessage();
                    break;
                }
            }
        }

        // Output text
        $text = is_string($object) ? $object : '';

        // Throw errors message
        if (!empty($errors)) {
            $text .= $this->_getErrorsMessage($errors);
        }

        return $text;
    }

    protected function _findAndReplaceEmbeddedMethods($owner, $text)
    {
        // Find all embedded methods
        preg_match_all(
            "/" . preg_quote(self::__EMBED_OPEN) . "(.*?)" . preg_quote(self::__EMBED_CLOSE) . "/s",
            $text,
            $embed_matches
        );

        $newText = $text;

        // Replace each template embed by computed text
        foreach ($embed_matches[0] as $embeddedMethod) {
            $embeddedText = $this->_embeddedMethodToText($owner, $embeddedMethod);
            $newText = str_replace($embeddedMethod, $embeddedText, $newText);
        }

        return $newText;
    }

    protected function _embeddedMethodToText($owner, $embeddedMethod)
    {

    }

    /**
     * @param $errors
     * @return string
     */
    protected function _getErrorsMessage($errors)
    {
        return "\n<!-- " . count($errors) . " error(s):\n\t" . implode("\n\t", $errors) . "\n -->\n";
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
    protected function _cannotGetMethodOfNonObject($fnName)
    {
        return "Cannot get method \"$fnName\" of non-object.";
    }

}