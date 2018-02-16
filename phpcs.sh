#!/bin/bash

set -e

if [ -f ./vendor/bin/phpcs ]; then
    COMMAND=./vendor/bin/phpcs
elif [ -f ./bin/phpcs ] ; then
    COMMAND=./bin/phpcs
else
    if [ ! -f ~/.analysis/phpcs/vendor/bin/phpcs ]; then
        echo "No local installation of phpcs found, installing phpcs... " >&2
        export COMPOSER_HOME=~/.analysis/phpcs
        composer global require --dev 'squizlabs/php_codesniffer:2.*' >&2
    fi

    COMMAND=~/.analysis/phpcs/vendor/bin/phpcs
fi

if [ "$1" == "--config-set" ] || [ "$1" == "--config-delete" ] || [ "$1" == "--config-show" ] || [ "$1" == "--help" ] || [ "$1" == "--version" ]; then

    $COMMAND --colors "$@"

else

    rm -f /tmp/analysis_result_phpcs

    for i in "$@"; do
        case $i in
            *--standard=*) CONFIGURATION_FILE=$(echo "$i" | cut -d '=' -f 2)
            ;;
            *)
                if [ -d "$i" ] || [ -f "$i" ]; then
                    CHECK_PATH=$i
                fi
            ;;
        esac
    done

    if [ -z "$CONFIGURATION_FILE" ] || [ ! -f $CONFIGURATION_FILE ]; then
        if [ -f ./phpcs.xml ]; then
            CONFIGURATION_FILE=phpcs.xml
        elif [ -f ./phpcs.xml.dist ]; then
            CONFIGURATION_FILE=phpcs.xml.dist
        else
            if [ -z "$CHECK_PATH" ] ; then
                echo -e "No Configuration File Found, please add 'phpcs.xml' in root directory:\n<?xml version=\"1.0\"?>\n<ruleset>\n    <file>./</file>\n    <exclude-pattern>./vendor/*</exclude-pattern>\n    <rule ref=\"PSR1\" />\n</ruleset>" >&2
                exit 1;
            fi
        fi
    fi

    INSTALLED_PATHS=""
    function append_install_path {
      if [ "$INSTALLED_PATHS" = "" ]; then
        INSTALLED_PATHS=$1
      else
        INSTALLED_PATHS="$INSTALLED_PATHS,$1"
      fi
    }

    if [ ! -z "$CONFIGURATION_FILE" ] && [ -f $CONFIGURATION_FILE ]; then

        echo "Setting configuration file : $CONFIGURATION_FILE" >&2

        if ! grep -q "<file>" $CONFIGURATION_FILE >&2; then
            sed -i '/<\/ruleset>/i <file>\.\/<\/file>' $CONFIGURATION_FILE >&2;
        fi

        if [[ ! -d ~/.analysis/phpcs/ ]]; then
            mkdir -p ~/.analysis/phpcs/;
        fi

        if grep "Ecg" $CONFIGURATION_FILE >&2; then
          if [ -d ./vendor/magento-ecg/coding-standard/ ]; then
            append_install_path "./vendor/magento-ecg/coding-standard"
          else
            composer require -d ~/.analysis/phpcs/ "magento-ecg/coding-standard" >&2
            append_install_path ~/.analysis/phpcs/vendor/magento-ecg/coding-standard
          fi
        fi

        if grep "WordPress" $CONFIGURATION_FILE >&2; then
          if [ -d ./vendor/wp-coding-standards/wpcs/WordPress ]; then
            append_install_path "./vendor/wp-coding-standards/wpcs"
          else
            composer require -d ~/.analysis/phpcs/ "wp-coding-standards/wpcs" >&2
            append_install_path ~/.analysis/phpcs/vendor/wp-coding-standards/wpcs
          fi
        fi

        if grep "Drupal" $CONFIGURATION_FILE >&2; then
          if [ -d ./vendor/drupal/coder/coder_sniffer ]; then
            append_install_path "./vendor/drupal/coder/coder_sniffer"
          else
            composer require -d ~/.analysis/phpcs/ "drupal/coder" >&2
            append_install_path ~/.analysis/phpcs/vendor/drupal/coder/coder_sniffer
          fi
        fi

        if [ "$INSTALLED_PATHS" != "" ]; then
          $COMMAND --config-set installed_paths $INSTALLED_PATHS >&2
        fi
    fi

    echo "php_code_sniffer" > "${SCRUTINIZER_VERSION_FILE:-/dev/null}"
    $COMMAND --version >> "${SCRUTINIZER_VERSION_FILE:-/dev/null}"

    VERSION="unknown"
    if [ -f "$SCRUTINIZER_VERSION_FILE" ]; then
      VERSION=$(cat $SCRUTINIZER_VERSION_FILE)
    fi

    echo "Running $COMMAND --report=checkstyle $* ($VERSION)" >&2

    $COMMAND -p --colors --report-checkstyle=/tmp/analysis_result_phpcs "$@" >&2 || true

    cat /tmp/analysis_result_phpcs
    rm -f /tmp/analysis_result_phpcs

fi
