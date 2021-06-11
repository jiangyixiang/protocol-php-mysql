<?php
/**
 * 背锅人          gushi Jiang
 * create_date    2021/6/11
 * create_time    2:09 下午
 */

/**
 * TP扒过来的浏览器友好的变量输出
 * @access public
 * @param mixed $var 变量
 * @param boolean $echo 是否输出(默认为 true，为 false 则返回输出字符串)
 * @param string|null $label 标签(默认为空)
 * @return null|string
 */
function dump($var, $echo = true, $label = null, $flags = ENT_SUBSTITUTE) {
    $label = (null === $label) ? '' : rtrim($label) . ':';

    ob_start();
    var_dump($var);
    $output = preg_replace('/\]\=\>\n(\s+)/m', '] => ', ob_get_clean());

    if (php_sapi_name() == 'cli') {
        $output = PHP_EOL . $label . $output . PHP_EOL;
    } else {
        if (!extension_loaded('xdebug')) {
            $output = htmlspecialchars($output, $flags);
        }
        $output = '<pre>' . $label . $output . '</pre>';
    }

    if ($echo) {
        echo($output);
        return null;
    }

    return $output;
}