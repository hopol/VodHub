<?php
/**
 * 空壳文件：tplInclude() 在「当前模板」与「default」都缺该文件时返回这里。
 *
 * 作用只有一个 —— 保证 require 的目标一定存在。
 * 少一个 header/footer 只是页面少一段尾巴，比整页 500 好得多。
 * 正常情况下走不到这里（default 始终带着全套文件）。
 *
 * 它放在 templates/ 根下而不是某个模板目录里：
 * listTemplates() 与 CI 的模板检查都只遍历 templates 下的**子目录**，
 * 所以这个文件不会被当成一套模板。
 */
?>
