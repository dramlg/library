<?php

namespace Bundle\CodeReviewBundle\Command\OpenSource;

use Bundle\BlogBundle\Documentation\HtmlGenerator;
use Bundle\CodeReviewBundle\Entity\Check;
use Bundle\CodeReviewBundle\Entity\IssueFilter\IssueSpecification;
use Bundle\CodeReviewBundle\Entity\IssueFilter\Label;
use Bundle\CodeReviewBundle\Entity\IssueFilter\Severity;
use Bundle\CodeReviewBundle\Entity\ProgrammingLanguage;
use Doctrine\ORM\EntityManager;
use Guzzle\Http\Client;
use Scrutinizer\Util\XmlUtils;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\CssSelector\CssSelector;
use Util\Controller\DoctrineUtils;


class GeneratePMDChecksCommand extends ContainerAwareCommand
{
    const RULESET_SOURCE_FILE_ROOT = 'https://github.com/pmd/pmd/blob/master/pmd-java/src/main/resources/rulesets/java/';
    const RULES_HTML_ROOT = 'https://pmd.github.io/pmd-5.5.1/pmd-java/rules/java/';

    /** @var  HtmlGenerator */
    private $htmlGenerator;
    
    /** @var  EntityManager */
    private $em;

    protected function configure()
    {
        $this
            ->setName('oss:generate-checks:pmd')
            ->setDescription('Generates checks for pmd specifications. See https://github.com/scrutinizer-ci/web/issues/617 for details.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'perform a dry run and print changes to stdout')
        ;
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

        foreach ($rules as $ruleName => $specifications) {
            $this->processRule($ruleName, $specifications, $output);
        }


        if ( ! $dryRun) {
            $this->em->flush();
        }
    }

    private function processRule($ruleName, $specifications, OutputInterface $output)
    {
        $output->writeln("\nProcessing rule: " . $ruleName);
        $ruleInstance = $this->getRuleInstance($ruleName);

        $this->enhanceSpecifications($specifications, $ruleName, $ruleInstance, $output);

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
            $check = $this->createCheck($ruleInstance, $ruleName, $output);
        }

        $this->enhanceCheck($check, $ruleName, $ruleInstance, $specifications, $output);
    }

    private function indexSpecifications(OutputInterface $output)
    {
        $rules = array();
        $specifications = $this->em->createQuery("SELECT s from CodeReviewBundle:IssueFilter\IssueSpecification s WHERE s.messageId LIKE :messageId")
            ->setParameter('messageId', 'PMD.' . '%')
            ->getResult();;

        if (empty($specifications)) {
            throw new \RuntimeException("Could not find any specifications with the PMD. message_id prefix. Empty DB or screwed up query?");
        }

        $output->writeln("Found: " . count($specifications) . " specifications");

        /** @var IssueSpecification $specification */
        foreach ($specifications as $specification) {
            $ruleName = substr($specification->getOriginalMessageId(), strlen('PMD.'));

            $output->writeln("Building store for $ruleName");

            $rules[$ruleName][] = $specification;
        }

        return $rules;
    }
    
    private function getRuleInstance($ruleName)
    {
        $rulesetName = $this->getRulesetName($ruleName);
        $rulesetForUrl = $this->getRulesetNameForUrl($rulesetName);
        $abstractRuleName = $this->getAbstractRuleName($ruleName);

        $url = self::RULESET_SOURCE_FILE_ROOT . $rulesetForUrl . '.xml';
        $content = $this->getFileByUrl($url);
        $doc = XmlUtils::safeParse($content);

        $description = '';
        $priority = '';
        $example = '';
        
        foreach ($doc->xpath('//rule') as $rule) {
            if ($abstractRuleName === (string)$rule->attributes()->name) {
                $description = (string) $rule->description;
                $priority = (int) $rule->priority;
                $example = (string) $rule->example;
                break;
            }
        }

        $properties = $this->parseRuleProperties($abstractRuleName, $rulesetForUrl);

        $ruleInstance = array($ruleName => array(
            'description' => $description,
            'priority' => $priority,
            'example' => $example,
            'properties' => $properties
        ));

        return $ruleInstance;
        
    }
    

    private function parseRuleProperties($abstractRuleName, $rulesetForUrl)
    {
        $ruleProperties = array();

        $url = self::RULES_HTML_ROOT . $rulesetForUrl . '.html';
        $content = $this->getFileByUrl($url);
        $dom = new \DOMDocument;
        $dom->loadHTML($content);

        $xpath = new \DOMXPath($dom);
        $divNode = $xpath->query(CssSelector::toXPath('a[name='.$abstractRuleName.']'))->item(0)->parentNode->parentNode;
        $tables = $xpath->query(CssSelector::toXPath('table'), $divNode);
        $propertyTableNode = $tables->item($tables->length - 1);

        foreach ($propertyTableNode->childNodes as $elementNode) {
            if ($elementNode -> nodename == 'tr') {
                $propertyName = $elementNode->childNodes->item(0)->nodeValue;
                $defaultValue = $elementNode->childNodes->item(1)->nodeValue;
                $description = $elementNode->childNodes->item(2)->nodeValue;
                $ruleProperties = array(
                    'name' => $propertyName,
                    'defaultValue' => $defaultValue,
                    'description' => $description
                );
            }
        }

        return $ruleProperties;
    }

    private function enhanceSpecifications(array $specifications, $ruleName, array $ruleInstance, OutputInterface $output)
    {
        foreach ($specifications as $specification) {
            /** @var IssueSpecification $specification */
            $output->writeln('Processing specification:' . $specification->getMessageId() . ' ('. $specification->getId(). ')');
            if (empty($specification->getDescription()) && isset($ruleInstance[$ruleName]['description'])) {
                $desc = $ruleInstance[$ruleName]['description'];
                $output->writeln('Setting new description: ' . $desc);

                $specification->setDescription($desc);
            }

            if ($specification->getSeverity() === Severity::UNKNOWN && isset($ruleInstance[$ruleName]['priority'])) {
                $priority = $ruleInstance[$ruleName]['priority'];
                $severity = $this->getDefaultSeverityFromPriority($priority);
                $output->writeln('Setting new severity: ' . Severity::getInstance($severity)->getLabel());

                $specification->setSeverity($severity);
            }

            $updatedlabelNames = $this->getLabelNamesFromRuleName($ruleName);

            if (!$specification->getLabels()->isEmpty() && !empty($updatedlabelNames)) {

                $labels = array();
                foreach($updatedlabelNames as $slugName => $labelName) {
                    $label = $this->findLabel($slugName, $labelName);
                    if(!empty($label)) {
                        $labels[] = $label;
                    }
                }

                $labelNames = array();
                /** @var Label $label */
                foreach ($labels as $label) {
                    $labelNames[] = $label->getName();
                }
                $output->writeln('Labels: ' . implode(', ', $labelNames));

                $specification->setLabels($labels);
            }

            $this->em->persist($specification);
        }
    }

    private function enhanceCheck(Check $check, $ruleName, array $ruleInstance, array $specifications, OutputInterface $output)
    {
        if (empty($check->getExplanationRst())) {

            $rst = $this->prepareExplanationRst($ruleName, $ruleInstance);
            if ( ! empty($rst)) {
                $output->writeln("Setting new explanation: \n" . $rst);
                $html = $this->htmlGenerator->generateHtml($rst);
                $check->setExplanation($rst, $html);
            } else {
                $output->writeln("Wanted to set new explanation, but did not get enough info to write one.");
            }
        }

        $sort = function (IssueSpecification $a, IssueSpecification $b) {
            if ($a->getId() == $b->getId()) {
                return 0;
            }
            if ($a->getId() > $b->getId()) {
                return 1;
            }

            return -1;
        };

        if ( ! empty(array_udiff($specifications, $check->getSpecifications(), $sort)) && ! empty(array_udiff($check->getSpecifications(), $specifications, $sort))) {
            $messageIds = array();
            /** @var IssueSpecification $specification */
            foreach ($specifications as $specification) {
                $messageIds[] = $specification->getMessageId();
            }
            $output->writeln("Setting message_ids: " . implode(', ', $messageIds));
            $check->setMessageIds($messageIds);
            $check->setSpecifications($specifications);
        }

        if ((empty($check->getDescription()) || $check->getDescription() === $ruleName) && isset($ruleInstance[$ruleName]['description'])) {
            $description = $ruleInstance[$ruleName]['description'];
            $output->writeln('Setting new description: ' . $description);
            $check->setDescription($description);
        }

        $check->setProtected(true);
        $this->em->persist($check);
    }

    private function findLabel($slugName, $labelName)
    {
        $label = $this->em->createQuery("SELECT l FROM CodeReviewBundle:IssueFilter\Label l WHERE l.slug = :slug")
            ->setParameter('slug', $slugName)
            ->getOneOrNullResult();

        if (empty($label)) {
            $label = $this->createLabel($labelName, $slugName);
        }

        return $label;
    }

    private function createLabel($labelName, $slugName, OutputInterface $output)
    {
        $output->writeln('Creating new Label: ' . $labelName);
        $label = new Label($labelName, $slugName);

        return $label;
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
            throw new \RuntimeException("Found more than one check matching runeName: " . $ruleName . '(' . array_keys($checks) . ')');
        } elseif (count($checks) == 1) {
            reset($checks);
        }
        
        $check = $this->$this->em->createQuery('SELECT c FROM CodeReviewBundle:Check c WHERE c.key = :key')
            ->setParameter('key', $ruleName)
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

    private function createCheck(array $ruleInstance, $ruleName, OutputInterface $output)
    {
        $output->writeln('Creating new check: ' . $ruleName);
        $desc = isset($ruleInstance[$ruleName]['description']) ? $ruleInstance[$ruleName]['description'] : $ruleName;
        $check = new Check(ProgrammingLanguage::getInstance(ProgrammingLanguage::JAVA), $ruleName, $desc);

        return $check;
    }

    private function prepareExplanationRst($ruleName, array $ruleInstance)
    {
        $example = $ruleInstance[$ruleName]['example'];
        $codeExample = str_replace(array('<![CDATA[', ']]>'), '', $example);

        $rst = '';

        if ($codeExample) {
            $rst = <<< TEMPLATE

Example(s):
-------------
.. code:: java

$codeExample

TEMPLATE;
        }

        $properties = $ruleInstance[$ruleName]['properties'];

        if ($properties) {
            $maxNameLen = 0;
            $maxValueLen = 0;
            $maxDescLen = 0;

            foreach($properties as $property) {
                $maxNameLen = $maxNameLen <= strlen($property['name']) ? strlen($property['name']) : $maxNameLen;
                $maxValueLen = $maxValueLen <= strlen($property['defaultValue']) ? strlen($property['defaultValue']) : $maxValueLen;
                $maxDescLen = $maxDescLen <= strlen($property['description']) ? strlen($property['description']) : $maxDescLen;
            }

            $tableElements = '';
            foreach($properties as $property) {
                $name = $property['name'];
                $defaultValue = $property['defaultValue'];
                $description = $property['description'];
                $tableElements .= str_pad($name, $maxNameLen+2) . str_pad($defaultValue, $maxValueLen+2) . str_pad($description, $maxDescLen+2) . "\n";
            }

            $tableContent = str_repeat('=', $maxNameLen) . '  ' . str_repeat('=', $maxValueLen) . '  ' . str_repeat('=', $maxDescLen) .
                "\n" . str_pad('Name', $maxNameLen+2) . str_pad('Default Value', $maxValueLen+2) . str_pad('Description', $maxDescLen+2) .
                "\n" . str_repeat('=', $maxNameLen) . '  ' . str_repeat('=', $maxValueLen) . '  ' . str_repeat('=', $maxDescLen) .
                "\n" . $tableElements .
                "\n" . str_repeat('=', $maxNameLen) . '  ' . str_repeat('=', $maxValueLen) . '  ' . str_repeat('=', $maxDescLen);

            $rst .= <<< TABLE
            
This rule has the following properties:

$tableContent

TABLE;
        }

        return $rst;
    }

    private function getDefaultSeverityFromPriority($priority)
    {
        switch ($priority) {
            case 1:
                return Severity::CRITICAL;
            case 2:
                return Severity::MAJOR;
            case 3:
                return Severity::MINOR;
            case 4:
                return Severity::INFO;
            case 5:
                return Severity::INFO;
            default:
                return Severity::UNKNOWN;
        }
    }

    private function getLabelNamesFromRuleName($ruleName)
    {
        $ruleSet = $this->getRulesetName($ruleName);

        switch ($ruleSet) {
            case "Android":
                return array('android' => 'Android');
            case "Basic":
                return array('bug' => 'Bug');
            case "Braces":
                return array('coding-style' => 'Coding Style');
            case "CloneImplementation":
                return array('best-practice' => 'Best Practice');
            case "CodeSize":
                return array('complexity' => 'Complextiy');
            case "EmptyCode":
                return array('unused-code' => 'Unused Code');
            case "Coupling":
                return array('best-practice' => 'Best Practice', 'comprehensibility' => 'Comprehensibility');
            case "Comments":
                return array('coding-style' => 'Coding Style', 'documentation' => 'Documentation');
            case "Finalizer":
                return array('best-practice' => 'Best Practice');
            case "ImportStatements":
                return array('unused-code' => 'Unused Code');
            case "J2EE":
                return array('best-practice' => 'Best Practice');
            case "JavaBeans":
                return array('best-practice' => 'Best Practice');
            case "JUnit":
                return array('best-practice' => 'Best Practice');
            case "JakartaCommonsLogging":
                return array('best-practice' => 'Best Practice');
            case "JavaLogging":
                return array('best-practice' => 'Best Practice');
            case "Migration":
                return array('compatibility' => 'Compatibility');
            case "Naming":
                return array('naming' => 'Naming');
            case "Optimization":
                return array('performance' => 'Performance');
            case "StrictExceptions":
                return array('best-practice' => 'Best Practice');
            case "StringandStringBuffer":
                return array('best-practice' => 'Best Practice');
            case "SecurityCodeGuidelines":
                return array('security' => 'Security');
            case "Unnecessary":
                return array('unused-code' => 'Unused Code');
            case "UnusedCode":
                return array('unused-code' => 'Unused Code');
            default:
                return array();
        }
    }

    private function getRulesetNameForUrl($rulesetName)
    {
        switch ($rulesetName) {
            case "Android":
                return 'android';
            case "Basic":
                return 'bug';
            case "Braces":
                return 'braces';
            case "CloneImplementation":
                return 'clone';
            case "CodeSize":
                return 'codesize';
            case "Controversial":
                return 'controversial';
            case "EmptyCode":
                return 'empty';
            case "Design":
                return 'design';
            case "Coupling":
                return 'coupling';
            case "Comments":
                return 'comments';
            case "Finalizer":
                return 'finalizers';
            case "ImportStatements":
                return 'imports';
            case "J2EE":
                return 'j2ee';
            case "JavaBeans":
                return 'javabeans';
            case "JUnit":
                return 'junit';
            case "JakartaCommonsLogging":
                return 'logging-jakarta-commons';
            case "JavaLogging":
                return 'logging-java';
            case "Migration":
                return 'migrating';
            case "Naming":
                return 'naming';
            case "Optimization":
                return 'optimizations';
            case "StrictExceptions":
                return 'strictexception';
            case "StringandStringBuffer":
                return 'strings';
            case "SecurityCodeGuidelines":
                return 'sunsecure';
            case "TypeResolution":
                return 'typeresolution';
            case "Unnecessary":
                return 'unnecessary';
            case "UnusedCode":
                return 'unusedcode';
            default:
                return '';
        }
    }

    private function getFileByUrl($url)
    {
        $client = new Client();
        $response = $client->get($url)->send();
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Cannot load ' . $url . ' due to: ' . $response->getReasonPhrase());
        }

        return $response->getBody();
    }

    private function getRulesetName($ruleName)
    {
        if (false !== strpos($ruleName, '.')) {
            return substr($ruleName, 0, strpos($ruleName, '.'));
        }

        return null;
    }

    private function getAbstractRuleName($ruleName)
    {
        if (false !== strpos($ruleName, '.')) {
            return substr($ruleName, strpos($ruleName, '.')+1);
        }

        return null;
    }

}
