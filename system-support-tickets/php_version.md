support Php minor version:

- You could run your tests in a Docker container which ships with the exact PHP version, or
- You could also run composer with ``--ignore-platform-reqs`` to ignore the constraint specifically in the build environment
