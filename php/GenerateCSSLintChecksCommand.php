<?php

namespace Bundle\CodeReviewBundle\Command\OpenSource;

use Bundle\BlogBundle\Documentation\HtmlGenerator;
use Bundle\CodeReviewBundle\Entity\Check;
use Bundle\CodeReviewBundle\Entity\IssueFilter\IssueSpecification;
use Bundle\CodeReviewBundle\Entity\IssueFilter\Label;
use Bundle\CodeReviewBundle\Entity\IssueFilter\Severity;
use Bundle\CodeReviewBundle\Entity\ProgrammingLanguage;
use Bundle\CodeReviewBundle\Util\MarkdownUtils;
use Doctrine\ORM\EntityManager;
use Guzzle\Service\Client;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Util\Controller\DoctrineUtils;

class GenerateCSSLintChecksCommand extends ContainerAwareCommand
{
    const RULES_SOURCE_FILE = 'https://raw.githubusercontent.com/CSSLint/csslint/master/dist/csslint-node.js';
    const RULES_SET_FILE = 'https://raw.githubusercontent.com/wiki/CSSLint/csslint/Rules.md';
    const RULES_DETAILS_ROOT = 'https://raw.githubusercontent.com/wiki/CSSLint/csslint/';

    /** @var  HtmlGenerator */
    private $htmlGenerator;

    /** @var  EntityManager */
    private $em;

    protected function configure()
    {
        $this
            ->setName('oss:generate-checks:csslint')
            ->setDescription('Generates checks for eslint specifications. See https://github.com/scrutinizer-ci/web/issues/635 for details.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'perform a dry run and print changes to stdout');
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        /** @var DoctrineUtils $utils */
        $utils = $this->getContainer()->get('doctrine_utils');
        $this->em = $utils->getEntityManager();
        $this->htmlGenerator = $this->getContainer()->get('blog.html_generator');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dryRun = $input->getOption('dry-run');
        $rules = $this->indexSpecifications($output);
        $rulesData = $this->loadRulesData($output);

        foreach ($rules as $ruleName => $specifications) {
            $this->processRule($ruleName, $specifications, $rulesData, $output);

            if ( ! $dryRun) {
                $this->em->flush();
            }
        }
    }

    private function processRule($ruleName, array $specifications, array $rulesData, OutputInterface $output)
    {
        $output->writeln("\nProcessing rule: " . $ruleName);
        $this->enhanceSpecifications($specifications, $ruleName, $rulesData, $output);

        $check = $this->findCheck($specifications, $ruleName);

        /** @var IssueSpecification $specification */
        foreach ($specifications as $key => $specification) {
            if ($specification->isIgnored() || $specification->hasChecks() === false) {
                unset($specifications[$key]);
            }
        }

        if (empty($specifications)) {
            if ($check !== null) {
                $output->writeln("Deleting check " . $check->getId());
                $this->em->persist($check);
                $check->delete();
            }

            return;
        }

        if ($check === null) {
            $check = $this->createCheck($rulesData, $ruleName, $output);
        }

        $this->enhanceCheck($check, $ruleName, $rulesData, $specifications, $output);
    }

    private function enhanceSpecifications(array $specifications, $ruleName, array $rulesData, OutputInterface $output)
    {
        foreach ($specifications as $specification) {
            /** @var IssueSpecification $specification */
            $output->writeln('Processing specification:' . $specification->getMessageId() . ' ('. $specification->getId(). ')');
            if (!isset($rulesData[$ruleName])) {
                $output->writeln('Cannot found reference data for: '. $ruleName);
                continue;
            }

            if (empty($specification->getDescription()) && isset($rulesData[$ruleName]['description'])) {
                $desc = $rulesData[$ruleName]['description'];
                $output->writeln('Setting new description: ' . $desc);

                $specification->setDescription($desc);
            }

            $rulesetName = isset($rulesData[$ruleName]['ruleset']) ? $rulesData[$ruleName]['ruleset'] : '';

            if ($specification->getSeverity() === Severity::UNKNOWN ) {
                $severity = $this->getDefaultSeverity($rulesetName);
                $output->writeln('Setting new severity: ' . Severity::getInstance($severity)->getLabel());

                $specification->setSeverity($severity);
            }

            if ($specification->getLabels()->isEmpty()) {
                $labels = $this->getDefaultLabels($rulesetName, $output);

                /** @var Label $label */
                $labelNames = array();
                foreach ($labels as $label) {
                    $labelNames[] = $label->getName();
                }
                $output->writeln('Labels: ' . implode(', ', $labelNames));

                $specification->setLabels($labels);
            }

            $this->em->persist($specification);
        }
    }

    private function enhanceCheck(Check $check, $ruleName, array $rulesData, array $specifications, OutputInterface $output)
    {

        if (empty($check->getExplanationRst()) && isset($rulesData[$ruleName])) {

            $rst = $this->prepareExplanationRst($ruleName, $rulesData);
            if ( ! empty($rst)) {
                $output->writeln("Setting new explanation: \n" . $rst);
                $html = $this->htmlGenerator->generateHtml($rst, true);
                $check->setExplanation($rst, $html);
            } else {
                $output->writeln("Wanted to set new explanation, but did not get enough info to write one.");
            }
        }

        if (empty($check->getSpecifications())) {
            $messageIds = array();
            foreach ($specifications as $specification) {
                $messageIds[] = $specification->getMessageId();
            }
            $output->writeln("Setting message_ids: " . implode(', ', $messageIds));
            $check->setMessageIds($messageIds);
            $check->setSpecifications($specifications);
        }

        if ((empty($check->getDescription()) || $check->getDescription() === $ruleName) && isset($rulesData[$ruleName]['description'])) {
            $description = $rulesData[$ruleName]['description'];
            $output->writeln('Setting new description: ' . $description);
            $check->setDescription($description);
        }

        $check->setProtected(true);
        $output->writeln("Setting checks protected");
        $this->em->persist($check);
    }

    private function prepareExplanationRst($ruleName, $rulesData)
    {
        $explanation = $rulesData[$ruleName]['explanation'];

        if (!empty($explanation)) {
            $explanation = preg_replace('/\n#+\sFurther\sReading[^#]+/', '', $explanation);
            $rst = MarkdownUtils::convertMarkdowntoRst($explanation);
        }


        $rst = <<< TEMPLATE

$rst

TEMPLATE;

        return $rst;
    }

    private function indexSpecifications(OutputInterface $output)
    {
        $rules = array();
        $specifications = $this->em->createQuery("SELECT s from CodeReviewBundle:IssueFilter\IssueSpecification s WHERE s.messageId LIKE :messageId")
            ->setParameter('messageId', 'net.csslint.' . '%')
            ->getResult();;

        if (empty($specifications)) {
            throw new \RuntimeException("Could not find any specifications with the net.csslint. message_id prefix. Empty DB or screwed up query?");
        }

        $output->writeln("Found: " . count($specifications) . " specifications");

        /** @var IssueSpecification $specification */
        foreach ($specifications as $specification) {
            $rule = substr($specification->getOriginalMessageId(), strlen('net.csslint.'));

            $output->writeln("Building store for $rule");

            $rules[$rule][] = $specification;
        }

        return $rules;
    }

    private function loadRulesData(OutputInterface $output)
    {
        $rulesData = array();
        $ruleSetData = $this->loadRuleSetData($output);
        $content = $this->getFileByUrl(self::RULES_SOURCE_FILE, $output);

        if(preg_match_all('/addRule\((\{([^{}]|(?1))*\})\)/', $content, $ruleBlocks)) {
            foreach ($ruleBlocks[0] as $ruleBlock) {
                $id = '';
                $name = '';
                $desc = '';
                $url = '';

                if (preg_match('/id:\s"([^"]+)"/', $ruleBlock, $matches)){
                    $id = $matches[1];
                }

                if (preg_match('/name:\s"([^"]+)"/', $ruleBlock, $matches)){
                    $name = $matches[1];
                }

                if (preg_match('/desc:\s"([^"]+)"/', $ruleBlock, $matches)){
                    $desc = $matches[1];
                }

                if (preg_match('/url:\s"([^"]+)"/', $ruleBlock, $matches)){
                    $url = $matches[1];
                }

                // rules that haven't explanation are not listed in the Rules.md either
                if (isset($ruleSetData[$name])) {
                    $ruleSetName = $ruleSetData[$name];
                    $explanation = $this->getExplanation($url, $output);
                }else{
                    $ruleSetName = '';
                    $explanation = '';
                }


                if(!empty($id) && !empty($name)) {
                    $messageId = str_replace(' ', '', $name);
                    $rulesData[$messageId] = array(
                        'id' => $id,
                        'name' => $name,
                        'description' => $desc,
                        'ruleset' => $ruleSetName,
                        'explanation' => $explanation
                    );
                }
            }
        }

        return $rulesData;
    }

    private function loadRuleSetData(OutputInterface $output)
    {
        $ruleSetData = array();
        $content = $this->getFileByUrl(self::RULES_SET_FILE, $output);
        if (preg_match_all('/(\n|^)##\s[^#]+/', $content, $blocks)) {
            foreach ($blocks[0] as $block) {
                $ruleSetName = '';
                if (preg_match('/(\n|^)##\s(.+)/', $block, $title)) {
                    $ruleSetName = $title[2];
                }
                if (preg_match_all('/(\n|^)\*\s`([^`]+)`\:\s\[\[([^[\]]+)\]\]/', $block, $ul)) {
                    foreach ($ul[3] as $ruleName) {
                        $ruleSetData[$ruleName] = $ruleSetName;
                    }
                }
            }
        }
        return $ruleSetData;
    }

    private function getExplanation($wikiUrl, OutputInterface $output)
    {
        $url = self::RULES_DETAILS_ROOT . end(explode('/', $wikiUrl)) . '.md';
        $content = $this->getFileByUrl($url, $output);

        return $content;
    }

    private function getDefaultSeverity($rulesetName)
    {
        switch ($rulesetName) {
            case "Possible Errors":
                return Severity::MINOR;
            case "SECURITY":
                return Severity::MAJOR;
            case "Best Practices":
                return Severity::MINOR;
            case "Stylistic Issues":
                return Severity::INFO;
            case "Variables":
                return Severity::MINOR;
            default:
                return Severity::UNKNOWN;
        }
    }

    private function getDefaultLabels($rulesetName, OutputInterface $output)
    {
        $labels = array();

        $labelNames =$this->getLabelNamesFromRuleName($rulesetName);

        foreach ($labelNames as $labelName => $slugName) {
            $label = $this->findLabel($slugName, $labelName, $output);
            if(!empty($label)) {
                $labels[] = $label;
            }
        }
        return $labels;
    }

    private function findLabel($slugName, $labelName, OutputInterface $output)
    {
        $label = $this->em->createQuery("SELECT l FROM CodeReviewBundle:IssueFilter\Label l WHERE l.slug = :slug")
            ->setParameter('slug', $slugName)
            ->getOneOrNullResult();

        if ($label === null) {
            $output->writeln("label not found, create a new one named", $labelName);
            $label = $this->createLabel($labelName, $slugName, $output);
        }

        return $label;
    }

    private function createLabel($labelName, $slugName, OutputInterface $output)
    {
        $output->writeln('Creating new Label: ' . $labelName);
        $label = new Label($labelName, $slugName);

        $this->em->persist($label);
        return $label;
    }

    private function getLabelNamesFromRuleName($rulesetName)
    {
        switch ($rulesetName) {
            case "Possible Errors":
                return array('Bug' => 'bug');

            case "Compatibility":
                return array('Compatibility' => 'compatibility');

            case "Performance":
                return array('Performance' => 'performance');

            case "Maintainability & Duplication":
                return array('Complexity' => 'complexity');

            case "Accessibility":
                return array('Best Practice' => 'best-practice');

            default:
                return array();
        }
    }

    private function findCheck(array $specifications, $ruleName)
    {
        $checks = array();
        foreach ($specifications as $specification) {
            $check = $this->getMatchedCheck($specification);
            if ($check === null) {
                continue;
            }

            $checks[$check->getId()] = $check;
        }
        if (count($checks) > 1) {
            throw new \RuntimeException("Found more than one check matching ruleName: " . $ruleName . '(' . array_keys($checks) . ')');
        } elseif (count($checks) == 1) {
            reset($checks);
        }

        $check = $this->em->createQuery('SELECT c FROM CodeReviewBundle:Check c WHERE c.key = :key')
            ->setParameter('key', 'net.csslint.' . $ruleName)
            ->getOneOrNullResult();
        if ($check !== null) {
            return $check;
        }

        return null;
    }

    /**
     * @return Check|null
     */
    private function getMatchedCheck(IssueSpecification $spec)
    {
        $checkIds = $this->em->getConnection()
            ->executeQuery("SELECT c.check_id FROM code_review_checks_specifications AS c WHERE c.issuespecification_id = :specId", array('specId' => $spec->getId()))
            ->fetchAll();
        if (count($checkIds) > 1) {
            throw new \LogicException('Found multiple checks for issue specification: ' . $spec->getMessageId());
        }

        if (empty($checkIds)) {
            return null;
        }

        return $this->em->createQuery("SELECT c FROM CodeReviewBundle:Check c WHERE c.id = :sid")
            ->setParameter('sid', $checkIds[0]['check_id'])
            ->getOneOrNullResult();
    }

    private function createCheck(array $rulesData, $ruleName, OutputInterface $output)
    {
        $output->writeln('Creating new check: ' . $ruleName);
        $desc = isset($rulesData[$ruleName]['description']) ? $rulesData[$ruleName]['description'] : $ruleName;
        $check = new Check(ProgrammingLanguage::getInstance(ProgrammingLanguage::JAVASCRIPT), 'net.csslint.' . $ruleName, $desc);

        return $check;
    }

    private function getFileByUrl($url, OutputInterface $output)
    {
        $client = new Client();
        $response = $client->get($url)->send();
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Cannot load ' . $url . ' due to: ' . $response->getReasonPhrase());
        }

        $output->writeln("Getting Contents From " . $url);
        return $response->getBody();
    }
}