<?php

namespace Bundle\CodeReviewBundle\Command\OpenSource;

use Bundle\BlogBundle\Documentation\HtmlGenerator;
use Bundle\CodeReviewBundle\Entity\Check;
use Bundle\CodeReviewBundle\Entity\IssueFilter\IssueSpecification;
use Bundle\CodeReviewBundle\Entity\IssueFilter\Label;
use Bundle\CodeReviewBundle\Entity\IssueFilter\Severity;
use Bundle\CodeReviewBundle\Entity\ProgrammingLanguage;
use Doctrine\ORM\EntityManager;
use Guzzle\Service\Client;
use Guzzle\Http\Exception\ClientErrorResponseException;
use Scrutinizer\Configuration;
use Scrutinizer\Unitory\GitRepository;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Util\Controller\DoctrineUtils;

class GeneratePylintChecksCommand extends ContainerAwareCommand
{

    /** @var  HtmlGenerator */
    private $htmlGenerator;

    /** @var  EntityManager */
    private $em;

    protected function configure()
    {
        $this
            ->setName('oss:generate-checks:pylint')
            ->setDescription('Generates checks for pylint specifications. See https://github.com/scrutinizer-ci/web/issues/645 for details.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'perform a dry run and print changes to stdout')//->addOption('automated-fix-label', null, InputOption::VALUE_REQUIRED, 'sets the label slug for the automated fixes label. Defaults to automated-fix.', 'automated-fix')
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
        $process = new Process('bash -s');
        $process->setStdin(<<<BASH
set -e

if [ ! -d /tmp/pylint ]; then
    mkdir /tmp/pylint
fi     
sudo pip install virtualenv >&2
virtualenv /tmp/pylint >&2
/tmp/pylint/bin/pip install pylint >&2

pylint --full-documentation

rm -rf /tmp/pylint
BASH
);
        $process->setTimeout(120);
        $process->mustRun();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $errOutput = $process->getErrorOutput();
        $doc = $process->getOutput();

        $output->writeln($errOutput);

        $dryRun = $input->getOption('dry-run');
        $messages = $this->indexSpecifications($output);
        $checkNodes = $this->getChecksNode();
        $checkerData = $this->loadCheckerData($doc, $output);

        foreach ($messages as $messageCode => $specifications) {
            $this->process($messageCode, $specifications, $checkNodes, $checkerData, $output);

            if (!$dryRun) {
                $this->em->flush();
            }
        }

    }

    private function process($messageCode, array $specifications, $checkNodes, $checkerData, OutputInterface $output)
    {
        $output->writeln("\nProcessing rule: " . $messageCode);

        $messageInstance = array('checkerName' => '', 'key' => '', 'code' => '', 'description' => '', 'explanation' => '');
        foreach ($checkerData as $checkerName => $checker) {
            foreach ($checker as $message) {
                if ($message['code'] === $messageCode || $message['key'] === $messageCode) {
                    $messageInstance['checkerName'] = $checkerName;
                    $messageInstance['key'] = $message['key'];
                    $messageInstance['code'] = $message['code'];
                    if (preg_match('/(Used|Emitted)\s+when(?:(?!\.\s+).)+/s', $message['description'], $match)) {
                        $messageInstance['description'] = preg_replace('/(Used|Emitted)\s+when/', 'Checks whether', $match[0]);
                    }else{
                        $messageInstance['description'] = 'Checks '. str_replace('-', ' ', $message['key']);
                    }
                    $messageInstance['explanation'] = $message['description'];
                    break;
                }
            }
        }

        if(empty($messageInstance['key'])){
            $output->writeln('Not Found reference data for specification: '. $messageCode);
            return;
        }

        $this->enhanceSpecifications($specifications, $messageInstance, $output);

        $check = $this->findCheck($specifications, $messageInstance, $checkNodes);

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
            $check = $this->createCheck($messageInstance, $output);
        }

        $this->enhanceCheck($check, $messageInstance, $specifications, $output);
    }

    private function enhanceSpecifications(array $specifications, $messageInstance, OutputInterface $output)
    {
        /** @var IssueSpecification $specification */
        foreach ($specifications as $specification) {
            $output->writeln('Processing specification:' . $specification->getMessageId() . ' ('. $specification->getId(). ')');
            if (empty($specification->getDescription())) {
                $desc = $messageInstance['description'];
                $output->writeln('Setting new description: ' . $desc);

                $specification->setDescription($desc);
            }

            if ($specification->getSeverity() === Severity::UNKNOWN) {
                $severity = $this->getSeverityByCode($messageInstance['code']);
                $output->writeln('Setting new severity: ' . Severity::getInstance($severity)->getLabel());

                $specification->setSeverity($severity);
            }

            $updateLabels = $this->getLabelNames($messageInstance['checkerName']);
            if ($specification->getLabels()->isEmpty() && !empty($updateLabels)) {
                $labels = array();
                foreach($updateLabels as $slugName => $labelName) {
                    $label = $this->findLabel($slugName, $labelName, $output);
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

    private function enhanceCheck(Check $check, array $messageInstance, array $specifications, OutputInterface $output)
    {
        //setExplanation
        if (empty($check->getExplanationRst()) && isset($messageInstance['explanation'])) {

            $rst = $this->prepareExplanationRst($messageInstance['explanation']);
            if ( ! empty($rst)) {
                $output->writeln("Setting new explanation: \n" . $rst);
                $html = $this->htmlGenerator->generateHtml($rst, true);
                $check->setExplanation($rst, $html);
            } else {
                $output->writeln("Wanted to set new explanation, but did not get enough info to write one.");
            }
        }

        //setMessageIds && setSpecifications
        $messageIds = $check->getMessageIds();
        $defaultSpecs = $check->getSpecifications();
        foreach ($specifications as $specification) {
            $specExist = false;
            foreach ($defaultSpecs as $defaultSpec) {
                if ($specification->getId() === $defaultSpec->getId()) {
                    $specExist = true;
                    break;
                }
            }

            if (! $specExist) {
                $messageIds[] = $specification->getMessageId();
                $defaultSpecs[] = $specification;
            }
        }
        $output->writeln("Setting message_ids: " . implode(', ', $messageIds));
        $check->setMessageIds($messageIds);
        $check->setSpecifications($defaultSpecs);

        //setDescription
        $configKey = $messageInstance['checkerName'].str_replace('-', '_', $messageInstance['key']).'.'.$messageInstance['code'];
        if ((empty($check->getDescription()) || $check->getDescription() === $configKey) && isset($messageInstance['description'])) {
            $description = $messageInstance['description'];
            $output->writeln('Setting new description: ' . $description);
            $check->setDescription($description);
        }

        //setNewKey
        if (false === strpos($check->getKey(), 'pylint.')) {
            $output->writeln('Update old Key: <'.$check->getKey().'> to new Key: <pylint.'. $configKey.'>');
            $check->setKey('pylint.'.$configKey);
        }

        $check->setProtected(true);
        $this->em->persist($check);
    }

    private function prepareExplanationRst($explanation)
    {
        $rst = '';
        if(!empty($explanation)) {
            $rst .= <<< TEMPLATE
            
$explanation

TEMPLATE;
        }
        return $rst;
    }

    private function indexSpecifications(OutputInterface $output)
    {
        $messages = array();
        $specifications = $this->em->createQuery("SELECT s from CodeReviewBundle:IssueFilter\IssueSpecification s  WHERE s.messageId LIKE :messageId")
            ->setParameter('messageId', 'pylint.%')
            ->getResult();;

        if (empty($specifications)) {
            throw new \RuntimeException("Could not find any specifications with the pylint. message_id prefix. Empty DB or screwed up query?");
        }

        $output->writeln("Found: " . count($specifications) . " specifications");

        /** @var IssueSpecification $specification */
        foreach ($specifications as $specification) {
            $messageCode = substr($specification->getOriginalMessageId(), strlen('pylint.'));

            $output->writeln("Building store for $messageCode");

            $messages[$messageCode][] = $specification;
        }

        return $messages;
    }

    private function getChecksNode()
    {
        /**
         * return array(path => key), path = {checkerName.message-key}
         */
        $messages = array(); // path => key
        /** @var ArrayNode $configNode */
        $configNode = (new Configuration(array()))->getTree();

        /** @var ArrayNode $checkNode */
        $checkNode = $configNode->getChildren()['checks'];
        $checkNode = $checkNode->getChildren()['python'];

        /**
         * @var  ArrayNode $settingsNode
         */
        foreach ($checkNode->getChildren() as $checkKey => $settingsNode) {
            if ( ! $settingsNode->hasAttribute('path')) {
                //should not happen but I'm not taking chances, who knows what else will screw with the checks
                continue;
            }

            $path = $settingsNode->getAttribute('path');

            if (false !== strpos($path, 'pylint')) {
                $messageName = $this->getMessageNameFromPath($path);
                $messages[$messageName] = $checkKey;
            }

        }

        return $messages;
    }
    
    private function getMessageNameFromPath($path)
    {
        $path = substr($path, strlen('pylint.'));
        $messageName = '';
        $nameGroup = explode('.', $path);
        if (empty($nameGroup)) {
            return '';
        }
        
        foreach ($nameGroup as $name) {
            $messageName .= str_replace('_', '-', $name);
        }
        return $messageName;
    }

    private function loadCheckerData($doc, OutputInterface $output)
    {
        $checkerData = array();

        if (empty($doc)) {
            throw new \RuntimeException('Cannot load pylint documentation data');
        }

        if (preg_match_all('/\n[^\n]+checker\s+~+(?:(?!\n[^\n]+checker\s+~+).)+/s', $doc, $blocks)) {
            foreach ($blocks[0] as $block) {
                if (preg_match('/Verbatim\s+name\s+of\s+the\s+checker\s+is\s+``([^`]+)``/', $block, $matchedCheckerName)) {
                    $checkerName = $matchedCheckerName[1];
                }

                if (empty($checkerName)) {
                    $output->writeln('find checker content block, but cant parse checker name, please check regex pattern and changes of documentation');
                    continue;
                }

                if (preg_match('/Messages\s+~+.+/s', $block, $messageBlock)) {
                    $regex = "/:(?P<key>[^\s]+)\s*\((?P<code>[A-Z]\d+)\):\s\*(?P<message>[^\*]+)\*\s*(?P<desc>(?:(?!\n:|\n\n).)+)/s";
                    preg_match_all($regex, $messageBlock[0], $messages);
                    foreach ($messages['key'] as $k => $key) {
                        $code = $messages['code'][$k];
                        $description = $messages['desc'][$k];
                        $description = preg_replace( array("/\r|\n|\t/", '/\s+/') , array(' ', ' '), $description);
                        $checkerData[$checkerName][] = array(
                            'key' => $key,
                            'code' => $code,
                            'description' => $description
                        );
                    }
                }
            }
        }
        return $checkerData;
    }

    private function getSeverityByCode($code)
    {
        $messageType = '';
        if(preg_match('/([A-Z])\d+/',$code, $match)) {
            $messageType = $match[1];
        }

        switch ($messageType) {
            case "R":
                return Severity::INFO;
            case "C":
                return Severity::INFO;
            case "W":
                return Severity::MINOR;
            case "E":
                return Severity::MAJOR;
            case "F":
                return Severity::CRITICAL;
            default:
                return Severity::UNKNOWN;

        }
    }

    private function getLabelNames($checkerName)
    {
        switch ($checkerName) {
            case "logging":
                return array();

            case "similarities":
                return array('duplication' => 'Duplication');

            case "format":
                return array('coding-style' => 'Coding Style');

            case "imports":
                return array();

            case "variables":
                return array();

            case "typecheck":
                return array();

            case "miscellaneous":
                return array('security' => 'Security');

            case "async":
                return array('bug' => 'Bug');

            case "refactoring":
                return array('unused-code' => 'Unused Code');

            case "classes":
                return array();

            case "design":
                return array('best-practice' => 'Best Practice');

            case "string_constant":
                return array('unused-code' => 'Unused Code');

            case "stdlib":
                return array();

            case "Spelling":
                return array('coding-style' => 'Coding Style');

            default:
                return array();
        }
    }

    private function findLabel($slugName, $labelName, OutputInterface $output)
    {
        $label = $this->em->createQuery("SELECT l FROM CodeReviewBundle:IssueFilter\Label l WHERE l.slug = :slug")
            ->setParameter('slug', $slugName)
            ->getOneOrNullResult();

        if ($label === null) {
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

    private function getCheckByKey($key)
    {
        return $this->em->createQuery('SELECT c FROM CodeReviewBundle:Check c WHERE c.key = :key')
            ->setParameter('key', $key)
            ->getOneOrNullResult();
    }

    private function findCheck($specifications, $messageInstance, $checkNodes)
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
            throw new \RuntimeException("Found more than one check matching message key: " . $messageInstance['key'] . '(' . array_keys($checks) . ')');
        } elseif (count($checks) == 1) {
            reset($checks);
        }

        $configPath = $messageInstance['checkerName'].str_replace('-', '_', $messageInstance['key']);
        if (isset($checkNodes[$configPath])) {
            $check =  $this->getCheckByKey($checkNodes[$configPath]);
            if ($check !== null) {
                return $check;
            }
        }

        $check = $this->getCheckByKey('pylint.'.$configPath.'.'.$messageInstance['code']);

        return $check;

    }

    private function createCheck(array $messageInstance, OutputInterface $output)
    {
        $configKey = $messageInstance['checkerName'].'.'.str_replace('-', '_', $messageInstance['key']).'.'.$messageInstance['code'];
        $output->writeln('Creating new check: ' . $configKey);
        $desc = isset($messageInstance['description']) ? $messageInstance['description'] : $configKey;
        $check = new Check(ProgrammingLanguage::getInstance(ProgrammingLanguage::PYTHON), 'pylint.'.$configKey, $desc);

        return $check;
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

}