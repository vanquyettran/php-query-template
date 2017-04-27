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
    const __EMBED_OPEN = '[[';
    const __EMBED_CLOSE = ']]';

    /**
     * @var string $content to store input text content
     *
     */
    public $content;

    /**
     * @var array $options
     */
    public $options;

    /**
     * @var array $functions
     */
    public $funcList;

    /**
     * @var string $newContent
     */
    protected $_newContent;

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        $this->_newContent = $this->content;
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
            "/" . preg_quote(self::__BLOCK_OPEN) . "(.*?)" . preg_quote(self::__BLOCK_CLOSE) . "/",
            $this->content,
            $block_matches
        );

        // Replace each template block by computed text
        foreach ($block_matches[0] as $block) {
            $text = $this->_blockToText($block);
            $this->_newContent = str_replace($block, $text, $this->_newContent);
        }

        return $this->_newContent;
    }

    /**
     * @return string $text from template block
     */
    protected function _blockToText($block)
    {
        $errors = [];
        $object = null;

        $inner = ltrim(rtrim($block, self::__BLOCK_CLOSE), self::__BLOCK_OPEN);
        $fns = explode('.', $inner);

        foreach ($fns as $i => $fn) {
            $fnName = $fn;
            $fnArgs = [];
            preg_match_all("/\((.*?)\)/", $fn, $args_matches);
            foreach ($args_matches[0] as $j => $args) {
                $fnName = trim(str_replace($args, '', $fnName));
                if ($j == 0) {
                    $fnArgs = explode(',', ltrim(rtrim($args, ')'), '('));
                    foreach ($fnArgs as &$fnArg) {
                        $fnArg = trim($fnArg);
                    }
                }
            }
            if ($i == 0) {
                if (!isset($this->funcList[$fnName])) {
                    $errors[] = $this->_functionDoesNotExistError($fnName);
                    break;
                }

                // Get object via static/first function
                $func = $this->funcList[$fnName];
                $object = $func(...$fnArgs);
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
                $object = call_user_func_array([$object, $fnName], $fnArgs);
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

    protected function _getErrorsMessage($errors)
    {
        return "\n<!-- " . count($errors) . " error(s):\n\t" . implode("\n\t", $errors) . "\n -->\n";
    }

    protected function _functionDoesNotExistError($fnName)
    {
        return "Function \"$fnName\" does not exist.";
    }

    protected function _methodDoesNotExistError($objName, $fnName)
    {
        return "Object \"$objName\" does not have the method \"$fnName\".";
    }

    protected function _cannotGetMethodOfNonObject($fnName)
    {
        return "Cannot get method \"$fnName\" of non-object.";
    }
}