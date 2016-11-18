```yaml

  build:
      dependencies:
          override:
              - mkdir -p ../phpcs
              - composer require -d ../phpcs --dev "wp-coding-standards/wpcs"
              - phpcs-run --config-set installed_paths ~/phpcs/vendor/wp-coding-standards/wpcs/
              - |
                cat - > phpcs.xml <<CONFIG
                <?xml version="1.0"?>
                  <ruleset>
                      <file>./</file>
                      <exclude-pattern>./vendor/*</exclude-pattern>

                      <rule ref="../phpcs/vendor/wp-coding-standards/wpcs" />
                </ruleset>
                CONFIG
      tests:
          override:
              - phpcs-run
            
```
