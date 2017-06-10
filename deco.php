#!/usr/bin/env php
<?php

include __DIR__ . '/vendor/autoload.php';
include __DIR__ . '/vendor/docopt/docopt/src/docopt.php';

use Symfony\Component\Yaml\Yaml;
use Michelf\MarkdownExtra;
use Flow\Loader;
use Flow\Adapter;
use Flow\Adapter\FileAdapter;

Loader::autoload();

define('DECO_VERSION', 'alpha version');

$script = substr($argv[0], 0, 1) == '.' ? $argv[0] : basename($argv[0]);
$doc = <<<DOC
Usage:
  $script init
  $script make <FILE>
  $script (-h | --help)
  $script --version

Options:
  -h --help     Show this screen.
  --version     Show version.

DOC;

class MemoryStreamWrapper
{
    static $memory = [];

    private $path;
    private $pos;

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $url = parse_url($path);

        $this->path = $url['host'] . ($url['path'] ?? '');

        $this->pos = 0;

        return true;
    }

    public function stream_read($count)
    {
        if (!isset(self::$memory[$this->path])) {
            self::$memory[$this->path] = '';
        }

        $ret = substr(self::$memory[$this->path], $this->pos, $count);

        $this->pos += strlen($ret);

        return $ret;
    }

    public function stream_write($data)
    {
        if (!isset(self::$memory[$this->path])) {
            self::$memory[$this->path] = '';
        }

        $left = substr(self::$memory[$this->path], 0, $this->pos);

        $right = substr(self::$memory[$this->path], $this->pos, strlen($data));

        self::$memory[$this->path] = $left . $data . $right;

        $len = strlen($data);

        $this->pos += $len;

        return $len;
    }

    public function stream_tell()
    {
        return $this->pos;
    }

    public function stream_eof()
    {
        return $this->pos >= strlen(self::$memory[$this->path]);
    }

    public function stream_seek($offset, $whence)
    {
        switch ($whence) {
        case SEEK_SET:
            if ($offset < strlen(self::$memory[$this->path]) && $offset >= 0) {
                $this->pos = $offset;
                return true;
            } else {
                return false;
            }
            break;

        case SEEK_CUR:
            if ($offset >= 0) {
                $this->pos += $offset;
                return true;
            } else {
                return false;
            }

        case SEEK_END:
            if (strlen(self::$memory[$this->path]) + $offset >= 0) {
                $this->pos = strlen(self::$memory[$this->path]) + $offset;
                return true;
            } else {
                return false;
            }

        default:
            return false;
        }
    }

    public function stream_metadata($path, $option, $var)
    {
        if ($option == STREAM_META_TOUCH) {
            $url = parse_url($path);
            $name = $url['host'] . $url['path'];
            if (!isset(self::$memory[$name])) {
                self::$memory[$name] = '';
            }
            return true;
        }
        return false;
    }

    public function stream_stat()
    {
        return [];
    }
}

stream_wrapper_register('memory', 'MemoryStreamWrapper');

class MemoryAdapter implements Adapter
{
    public function __construct(array $storage = [])
    {
        foreach ($storage as $path => $source) {
            file_put_contents('memory://' . $path, $source);
        }
    }

    public function isReadable(string $path) : bool
    {
        return true;
    }

    public function lastModified(string $path) : int
    {
        return filemtime(__FILE__);
    }

    public function getContents(string $path) : string
    {
        return file_get_contents('memory://' . $path);
    }

    public function putContents(string $path, string $contents) : int
    {
        return file_put_contents('memory://' . $path , $contents);
    }

    public function getStreamUrl(string $path) : string
    {
        return 'memory://' . $path;
    }
}

function deco_encode_email($obj = null, $mailto = false)
{
    $addr = "mailto:" . $obj;
    $chars = preg_split('/(?<!^)(?!$)/', $addr);
    $seed = (int)abs(crc32($addr) / strlen($addr)); # Deterministic seed.

    foreach ($chars as $key => $char) {
        $ord = ord($char);
        # Ignore non-ascii chars.
        if ($ord < 128) {
            $r = ($seed * (1 + $key)) % 100; # Pseudo-random function.
            # roughly 10% raw, 45% hex, 45% dec
            # '@' *must* be encoded. I insist.
            if ($r > 90 && $char != '@') /* do nothing */;
            else if ($r < 45) $chars[$key] = '&#x'.dechex($ord).';';
            else              $chars[$key] = '&#'.$ord.';';
        }
    }

    $addr = implode('', $chars);
    $text = implode('', array_slice($chars, 7)); # text without `mailto:`

    if ($mailto) {
        $addr = "<a href=\"$addr\">$text</a>";
    } else {
        $addr = $text;
    }

    return $addr;
}

function deco_render($file, $data = [])
{
    static $flow;

    if (!isset($flow)) {
        $flow = new Loader(
            Loader::RECOMPILE_ALWAYS,
            new FileAdapter(getcwd() . '/layouts'),
            new FileAdapter(getcwd() . '/cache'),
            [
                'encode_email' => 'deco_encode_email',
            ]
        );
    }

    try {
        $template = $flow->load($file);
        return $template->render($data + [
        ]);
    } catch (\Exception $e) {
        die($e->getMessage());
    }
}

function deco_parse($source, $data = [])
{
    static $flow;

    $path = md5($source) . '.html';

    if (!isset($flow)) {
        $flow = new Loader(
            Loader::RECOMPILE_ALWAYS,
            new MemoryAdapter([$path => $source]),
            new FileAdapter(sys_get_temp_dir()),
            [
                'encode_email' => 'deco_encode_email',
            ]
        );
    }

    try {
        $template = $flow->load($path);
        return $template->render($data + [
        ]);
    } catch (\Exception $e) {
        die($e->getMessage());
    }
}

function deco_init()
{
    if (!is_dir('./cache'))   mkdir('./cache', 0755);
    if (!is_dir('./layouts')) mkdir('./layouts', 0755);
    if (!is_dir('./files'))   mkdir('./files', 0755);
    if (!is_dir('./site'))    mkdir('./site', 0755);

    $data = <<<'YAML'
layout: default.html
author:
  name: Stuart Static
  site: http://www.example.com
  email: stuart.static@example.com
YAML;

    file_put_contents('./data.yml', $data);

    $style = <<<'CSS'
* { box-sizing: border-box; }

body {
  color: #000;
  background-color: #fff;
  font: 14px/1.5 Verdana, Arial, sans-serif;
  padding: 1em 0 1em 2em;
  margin: 0;
  width: 600px;
  text-align: justify;
}

h1,h2,h3 { font-weight: normal; margin: 0; }

h1 { font-size: 21px; margin-bottom: 0.25em; line-height: 1; }
h2 { font-size: 16px; margin: 18px 0 18px 0; }
h3 { font-size: 12px; margin: 14px 0 14px 0; font-weight: bold; }

.byline { font-size: 12px; margin: 0 0 1em; }
.byline a { text-decoration: none; }

p { margin: 1em 0; }
h1 + p, .byline + p { margin-top: 2em; }
a img { border: none; }

pre {
  margin: 1em 0;
  padding: 1em;
  background-color: #eee;
  white-space: pre-wrap;
  word-wrap: break-word;
}

blockquote {
  color: #555;
  margin: 2em;
  font-style: italic;
}

q { quotes: '\201c' '\201d' '\2018' '\2019' }
q:before { content: open-quote; }
q:after { content: close-quote; }

ins { text-decoration: none; background: #eee; }
CSS;

    file_put_contents('./files/style.css', $style);

    $index_layout =<<<'HTML'
<html>
<head>
<meta charset="utf-8">
<title>{{ title }}</title>
<link rel=stylesheet type="text/css" href="/style.css">
</head>
<body>
<h1>{{ title }}</h1>
{! body !}
</body>
</html>
HTML;

    file_put_contents('./layouts/index.html', $index_layout);

    $default_layout =<<<'HTML'
<html>
<head>
<meta charset="utf-8">
<title>{{ title }}</title>
<link rel=stylesheet type="text/css" href="/style.css">
</head>
<body>
<h1>{{ title }}</h1>
<p class=byline>By <a href="{{ author.site }}">{{ author.name }}</a> &lt;{{ author.email | encode_email }}&gt;</p>
{! body !}
</body>
</html>
HTML;

    file_put_contents('./layouts/default.html', $default_layout);

    $makefile = <<<'MAKE'
FILES=$(shell find files -type f -name '*' ! -name '.*')

TARGETS = $(patsubst %.md,%.html,$(patsubst files/%,site/%, $(FILES)))

.PHONY: clean flush

all: $(TARGETS)

clean:
	rm -rf site/*

flush:
	rm -rf cache/*

site/%: files/%
	@mkdir -p "$(@D)"
	cp $< $@

site/%.html: files/%.md data.yml
	@mkdir -p "$(@D)"
	deco make $< > $@
MAKE;

    file_put_contents('./Makefile', $makefile);

    $index_file = <<<'MARKDOWN'
---
layout: index.html
---

# Welcome to Deco

Edit `./files/index.md` and run "`make`" to update this page.

The following is a layout of the generated files and directories:

~~~
├── Makefile
├── cache
    └── .gitignore
├── data.yml
├── files
│   ├── index.md
│   └── style.css
├── layouts
│   ├── default.html
│   └── index.html
└── site
    ├── .gitignore
    ├── index.html
    └── style.css
~~~

Add files to the `./files` directory. Files with the `.md` extension will be
transformed and applied to their corresponding layout. Files with the `.html`
extension overrides `.md` files of the same base name. Layouts are located in
the `./layouts` directory. Markdown and layout files may use template tags and
access the data in `./data.yml` and the optional front matter at the top of each
markdown file. Deco uses the [Flow](http://github.com/nramenta/flow) templating
engine.

Run "`make`" to update the site.

Run "`make clean`" to delete all files in the `./site` directory.

Run "`make flush`" to delete all files in the `./cache` directory.
MARKDOWN;

    file_put_contents('./files/index.md', $index_file);

    $gitignore = <<<'GITIGNORE'
# Ignore everything in this directory
*
# Except this file
!.gitignore
GITIGNORE;

    file_put_contents('./cache/.gitignore', $gitignore);

    file_put_contents('./site/.gitignore', $gitignore);

    echo "type 'make' to build the site." . PHP_EOL;
}

function deco_make($source, $target = null)
{
    if (isset($target)) {
        $target = './site/' . basename($source, '.md') . '.html';
    }
    if (is_readable('./data.yml')) {
        $data = Yaml::parse(file_get_contents('./data.yml'));
    } else {
        $data = [];
    }
    if (is_readable($source)) {
        $markdown = preg_replace("/(\r\n|\r|\n)/", "\n", trim(file_get_contents($source)));
    } else {
        return false;
    }
    if (preg_match("/^---\s*\n(.+?)\n---\s*\n/", $markdown, $match)) {
        $front_matter = Yaml::parse($match[1]);
        $markdown = trim(substr($markdown, strlen($match[0])));
    } else {
        $front_matter = [];
    }
    if (preg_match("/^\#\s+(.+?)\n/", $markdown, $match)) {
        $title = $match[1];
        $markdown = trim(substr($markdown, strlen($match[0])));
    } else {
        $title = null;
    }
    $parsed = deco_parse($markdown, ['title' => $title] + $front_matter + $data);
    $body = MarkdownExtra::defaultTransform($parsed);
    $vars = ['title' => $title, 'body' => $body] + $front_matter + $data;
    if (is_null($vars['title']) && isset($front_matter['title'])) {
        $vars['title'] = $front_matter['title'];
    }
    $html = deco_render($vars['layout'], $vars);
    if (isset($target)) {
        return (bool) file_put_contents($target, $html);
    } else {
        return (bool) fwrite(STDOUT, $html);
    }
}

$args = Docopt::handle($doc, ['version' => 'Deco ' . DECO_VERSION]);

if ($args['init']) {
    return deco_init();
}

if ($args['make']) {
    return deco_make($args['<FILE>']);
}

