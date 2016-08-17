<?php

namespace Bundle\CodeReviewBundle\Util;

class MarkdownUtils
{
    /**
     * convert Markdown Format to rST Format
     * @param $content
     * @return bool|string
     */
    public static function convertMarkdowntoRst(&$content)
    {
        // Header
        $content = preg_replace_callback('/(\n|^)##\s(.*)/', function($matches)
        {
            return "\n".$matches[2]."\n".str_repeat('~', strlen($matches[2]));
        },$content);

        $content = preg_replace_callback('/(\n|^)###\s(.*)/', function($matches)
        {
            return "\n".$matches[2]."\n".str_repeat('^', strlen($matches[2]));
        },$content);

        // inline code
        $content = preg_replace_callback('/[^\S\n]`([^`]+)`[^\S\n]/', function($matches)
        {
            return ' ``'.$matches[1].'`` ';
        },$content);

        // code block
        $content = preg_replace_callback('/```(\w+)([^`]+)```/', function($matches)
        {
            return '.. code-block:: '.$matches[1]."\n".self::indent($matches[2], 4);
        },$content);

        // Link
        $content = preg_replace_callback('/\[([^\]]+)]\(([^\)]+)\)/', function($matches)
        {
            return '`'.$matches[1].' <'.$matches[2].'>`_ ';
        },$content);

        // ul-list
        $content = preg_replace_callback('/\n\*\s(.*)/', function($matches)
        {
            return "\n- ".$matches[1];
        },$content);

        return $content;

    }

    private function indent($str, $num)
    {
        if (preg_match("/^[^\n|\r]/",$str)){
            $str = "\n".$str;
        }

        return preg_replace("/\r|\n/", "\n".str_repeat(' ', $num), $str);
    }
}