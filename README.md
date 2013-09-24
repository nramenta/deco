# Deco - A simple static site generator

Deco is a simple static site generator.

## Installation

If you have [Box][Box] then simply run `box build` to generate `deco.phar` and
put that file somewhere in your path.

If you do not have Box, you can create a symbolic link from somewhere in your
path (`/usr/local/bin` might be a good choice) to `deco.php`.

You also need to make sure you have access to the `make` program.

## Usage

This section assumes you have successfully installed Deco somewhere in your path
as `deco`. Type `deco --version` to print out the version number.

Create an empty directory and change directory into it:

    mkdir mysite && cd mysite

Initialize Deco:

    deco init

The following is a layout of the generated files and directories:

```
├── Makefile
├── cache
    └── .gitignore
├── data.yml
├── files
│   ├── index.md
│   └── style.css
├── layouts
│   ├── default.html
│   └── index.html
└── site
    ├── .gitignore
    ├── index.html
    └── style.css
```

Add files to the `./files` directory. Files with the `.md` extension will be
transformed and applied to their corresponding layout. Files with the `.html`
extension overrides `.md` files of the same base name. Layouts are located in
the `./layouts` directory. Markdown and layout files may use template tags and
access the data in `./data.yml` and the optional front matter at the top of each
markdown file. Deco uses the [Flow][Flow] templating engine.

Run `make` to update the site.

Run `make clean` to delete all files in the `./site` directory.

Run `make flush` to delete all files in the `./cache` directory.

## License

Deco is released under the [MIT License][MIT].

[Box]: http://box-project.org/
[Flow]: http://github.com/nramenta/flow
[MIT]: http://en.wikipedia.org/wiki/MIT_License

