#"nvm use" not persisting

``nvm use`` isn't meant to persist - it's only for the lifetime of the shell.

You can either do ``nvm alias default node`` if you want that to be the default when opening new shells, or, you can make a ``.nvmrc`` file that will take precedence anywhere in the current directory, upwards to /.
