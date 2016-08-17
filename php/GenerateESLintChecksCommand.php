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

class GenerateESLintChecksCommand extends ContainerAwareCommand
{
    const RULES_BASIC_FILE = 'https://raw.githubusercontent.com/eslint/eslint.github.io/master/_data/rules.yml';
    const RULES_DETAILS_ROOT = 'https://raw.githubusercontent.com/eslint/eslint/master/docs/rules/';

    /** @var  HtmlGenerator */
    private $htmlGenerator;

    /** @var  EntityManager */
    private $em;

    private $automatedLabel = null;

    protected function configure()
    {
        $this
            ->setName('oss:generate-checks:eslint')
            ->setDescription('Generates checks for eslint specifications. See https://github.com/scrutinizer-ci/web/issues/635 for details.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'perform a dry run and print changes to stdout');
            //->addOption('automated-fix-label', null, InputOption::VALUE_REQUIRED, 'sets the label slug for the automated fixes label. Defaults to automated-fix.', 'automated-fix');
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        /** @var DoctrineUtils $utils */
        $utils = $this->getContainer()->get('doctrine_utils');
        $this->em = $utils->getEntityManager();
        $this->htmlGenerator = $this->getContainer()->get('blog.html_generator');
        //$this->preloadLabels($input->getOption('automated-fix-label'));
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

    private function indexSpecifications(OutputInterface $output)
    {
        $rules = array();
        $specifications = $this->em->createQuery("SELECT s from CodeReviewBundle:IssueFilter\IssueSpecification s WHERE s.messageId LIKE :messageId")
            ->setParameter('messageId', 'eslint.' . '%')
            ->getResult();;

        if (empty($specifications)) {
            throw new \RuntimeException("Could not find any specifications with the PMD. message_id prefix. Empty DB or screwed up query?");
        }

        $output->writeln("Found: " . count($specifications) . " specifications");

        /** @var IssueSpecification $specification */
        foreach ($specifications as $specification) {
            $rule = str_replace('_', '-', substr($specification->getOriginalMessageId(), strlen('eslint.')));

            $output->writeln("Building store for $rule");

            $rules[$rule][] = $specification;
        }

        return $rules;
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
            $rulesetName = $rulesData[$ruleName]['ruleset'];
            
            if (empty($specification->getDescription()) && isset($rulesData[$ruleName]['description'])) {
                $desc = $rulesData[$ruleName]['description'];
                $output->writeln('Setting new description: ' . $desc);

                $specification->setDescription($desc);
            }
            
            if ($specification->getSeverity() === Severity::UNKNOWN ) {
                $severity = $this->getDefaultSeverity($rulesetName);
                $output->writeln('Setting new severity: ' . Severity::getInstance($severity)->getLabel());

                $specification->setSeverity($severity);
            }
            
            if ($specification->getLabels()->isEmpty()) {
                $fixable = $rulesData[$ruleName]['fixable'];
                $labels = $this->getDefaultLabels($rulesetName, $fixable, $output);
                
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
                $html = $this->htmlGenerator->generateHtml($rst);
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

        $blocks = preg_split('/(\n#+\s.*)/', $explanation, 0, PREG_SPLIT_DELIM_CAPTURE);
        $rst = '';
        $ignored = false;

        foreach ($blocks as $key => $block) {
            // ignored the content in the section "Further|Related"
            if($ignored && !preg_match('/\n#+\s.*/', $block)) {
                $ignored = false;
                continue;
            }

            if (preg_match('/\n#+\s(Further|Related)/', $block)) {
                $ignored = true;
                continue;
            }

            if (preg_match('/^#\s(.*)/',$block)) {
                $block = preg_replace(array('/^#\s(.*)\n/', '/\(fixable\).*\n/'), '', $block);
            }

            $rst .= MarkdownUtils::convertMarkdowntoRst($block);

        }

        $rst = <<< TEMPLATE

$rst

TEMPLATE;

        return $rst;
    }

    private function loadRulesData(OutputInterface $output)
    {
        $rulesData = array();
        $content = $this->getFileByUrl(self::RULES_BASIC_FILE, $output);
        $yml = Yaml::parse($content);
        
        foreach ($yml['categories'] as $ruleset) {
            if (empty($ruleset)) {
                continue;
            }

            $rulesetName = $ruleset['name'];

            if (!isset($ruleset['rules'])) {
                continue;
            }
            
            foreach ($ruleset['rules'] as $rule) {
                $ruleName = $rule['name'];
                $explanation = $this->getExplanation($ruleName, $output);

                $rulesData[$ruleName] = array(
                    'description' => $rule['description'],
                    'ruleset' => $rulesetName,
                    'recommended' => $rule['recommended'],
                    'fixable' => $rule['fixable'],
                    'explanation' => $explanation,
                );

            }
        }
        return $rulesData;
    }

    private function getExplanation($ruleName, OutputInterface $output)
    {
        $url = self::RULES_DETAILS_ROOT . $ruleName . '.md';
        $content = $this->getFileByUrl($url, $output);

        return $content;
    }

    private function getDefaultLabels($rulesetName, $fixable, OutputInterface $output)
    {
        $labels = array();
        $labelNames = array();
        if($fixable === true) {
            $labelNames['Automated Fix'] = 'automatid-fix';
        }

        $labelNames = array_merge($labelNames, $this->getLabelNamesFromRuleName($rulesetName));

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

            case "SECURITY":
                return array('Security' => 'security');

            case "Best Practices":
                return array('Best Practice' => 'best-practice');

            case "Variables":
                return array('Best Practice' => 'best-practice');

            case "Stylistic Issues":
                return array('Coding Style' => 'coding-style');

            default:
                return array();
        }
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
            ->setParameter('key', 'eslint.' . $ruleName)
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
        $check = new Check(ProgrammingLanguage::getInstance(ProgrammingLanguage::JAVA), 'eslint.' . $ruleName, $desc);

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