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
use Util\Controller\DoctrineUtils;

class GenerateFindBugsChecksCommand extends ContainerAwareCommand
{
    const BUGPATTERN_DESC_SOURCE_FILE = 'https://raw.githubusercontent.com/findbugsproject/findbugs/master/findbugs/etc/messages.xml';
    const BUGPATTERN_RANK_FILE = 'https://raw.githubusercontent.com/findbugsproject/findbugs/master/findbugs/etc/bugrank.txt';
    const BUGPATTERN_SOURCE_FILE = 'https://raw.githubusercontent.com/findbugsproject/findbugs/master/findbugs/etc/findbugs.xml';

    /** @var  HtmlGenerator */
    private $htmlGenerator;

    /** @var  EntityManager */
    private $em;

    protected function configure()
    {
        $this
            ->setName('oss:generate-checks:findbugs')
            ->setDescription('Generates checks for findbugs specifications. See https://github.com/scrutinizer-ci/web/issues/628 for details.')
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
        $bugs = $this->indexSpecifications($output);
        $bugPatternData = $this->loadBugPatternData($output);

        foreach ($bugs as $bugName => $specifications) {
            $this->processBugPattern($bugName, $specifications, $bugPatternData, $output);

            if ( ! $dryRun) {
                $this->em->flush();
            }
        }
    }
    
    private function processBugPattern($bugName, array $specifications, array $bugPatternData, OutputInterface $output)
    {
        $output->writeln("\nProcessing bug pattern: " . $bugName);

        $this->enhanceSpecifications($specifications, $bugName, $bugPatternData, $output);

        $check = $this->findCheck($specifications, $bugName);

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
            $check = $this->createCheck($bugPatternData, $bugName, $output);
        }

        $this->enhanceCheck($check, $bugName, $bugPatternData, $specifications, $output);
    }

    private function enhanceSpecifications(array $specifications, $bugName, array $bugPatternData, OutputInterface $output)
    {
        $bugCategory = $this->getBugCategory($bugName);

        foreach ($specifications as $specification) {
            /** @var IssueSpecification $specification */
            $output->writeln('Processing specification:' . $specification->getMessageId() . ' ('. $specification->getId(). ')');
            if (empty($specification->getDescription()) && isset($bugPatternData[$bugName]['description'])) {
                $desc = $bugPatternData[$bugName]['description'];
                $output->writeln('Setting new description: ' . $desc);

                $specification->setDescription($desc);
            }

            if ($specification->getSeverity() === Severity::UNKNOWN && isset($bugPatternData[$bugName]['rank'])) {
                $rank = $bugPatternData[$bugName]['rank'];
                $severity = $this->getDefaultSeverityFromRank($rank);
                $output->writeln('Setting new severity: ' . Severity::getInstance($severity)->getLabel());

                $specification->setSeverity($severity);
            }

            $updatedlabelNames = $this->getLabelFromBugCategory($bugCategory);
            if ($specification->getLabels()->isEmpty()) {

                foreach($updatedlabelNames as $slugName => $labelName) {
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

    private function enhanceCheck(Check $check, $bugName, array $bugPatternData, array $specifications, OutputInterface $output)
    {
        if (empty($check->getExplanationRst())) {

            $rst = $this->prepareExplanationRst($bugName, $bugPatternData);
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

        if ((empty($check->getDescription()) || $check->getDescription() === $bugName) && isset($bugPatternData[$bugName]['description'])) {
            $description = $bugPatternData[$bugName]['description'];
            $output->writeln('Setting new description: ' . $description);
            $check->setDescription($description);
        }

        $check->setProtected(true);
        $output->writeln("Setting checks protected");
        $this->em->persist($check);
    }

    private function prepareExplanationRst($bugName, array $bugPatternData)
    {
        $rst = '';
        $details = isset($bugPatternData[$bugName]['details']) ? $bugPatternData[$bugName]['details'] : '';
        $details = str_replace(array('<![CDATA[', ']]>'), '', $details);

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($details);
        libxml_use_internal_errors(false);

        $content = $this->printRst($dom);

        if ($rst) {
            $rst .= <<< TEMPLATE
Details:
$content

TEMPLATE;
        }



        return $rst;
    }

    private function printRst(\DOMDocument $node)
    {
        if ($node->childNodes->length <= 0) {
            return $node->nodeValue;
        }

        $rst = '';
        for ($i=0; $i<$node->childNodes->length; $i++) {
            $rst .= $this->printRst($node->childNodes->item($i));
        }

        switch ($node->nodeName) {
            case 'i':
                return '*'.$rst.'*';

            case 'p':
                return $rst."\n\n";

            case 'a':
                return '`'. $rst . '<' . $node->getAttribute('href') . '>`_';

            case 'code':
                return '``'.$rst.'``';

            case 'pre':
                return '.. code-block:: '. "\n" . $rst;
        }

        return $rst;
    }

    private function indexSpecifications(OutputInterface $output)
    {
        $bugs = array();
        $specifications = $this->em->createQuery("SELECT s from CodeReviewBundle:IssueFilter\IssueSpecification s WHERE s.messageId LIKE :messageId")
            ->setParameter('messageId', 'findbugs.' . '%')
            ->getResult();;

        if (empty($specifications)) {
            throw new \RuntimeException("Could not find any specifications with the findbugs. message_id prefix. Empty DB or screwed up query?");
        }

        $output->writeln("Found: " . count($specifications) . " specifications");

        /** @var IssueSpecification $specification */
        foreach ($specifications as $specification) {
            $bugName = substr($specification->getOriginalMessageId(), strlen('findbugs.'));

            $output->writeln("Building store for $bugName");

            $bugs[$bugName][] = $specification;
        }

        return $bugs;
    }

    private function loadBugPatternData(OutputInterface $output)
    {
        $bugPatternData = array();
        $content = $this->getFileByUrl(self::BUGPATTERN_SOURCE_FILE, $output);
        $doc = XmlUtils::safeParse($content);

        $bugDescData = $this->loadBugDescData($output);
        $bugRankData = $this->loadBugRankData($output);

        foreach ($doc->xpath('//BugPattern') as $bugPattern){
            $bugPatternName = (string) $bugPattern->attributes()->type;
            $bugCategoryName = (string) $bugPattern->attributes()->category;
            $bugKind = (string) $bugPattern->attributes()->abbrev;

            $rank = $this->getBugPatternRank($bugPatternName, $bugCategoryName, $bugKind, $bugRankData, $output);

            if(isset($bugDescData[$bugPatternName])){
                $bugPatternData[] = array( $bugCategoryName.$bugPatternName => array(
                    'description' => $bugDescData[$bugPatternName]['description'],
                    'rank' => $rank,
                    'details' => $bugDescData[$bugPatternName]['details']
                ));
            }

        }

        return $bugPatternData;
    }

    private function loadBugDescData(OutputInterface $output)
    {
        $bugDescData = array();
        $content = $this->getFileByUrl(self::BUGPATTERN_DESC_SOURCE_FILE, $output);

        if(empty($content)){
            throw new \RuntimeException('Cannot load Description data for Bug Pattern');
        }

        $doc = XmlUtils::safeParse($content);
        foreach($doc->xpath('//BugPattern') as $bugPattern) {
            $bugPatternName = (string) $bugPattern->attributes()->type;
            $description = (string) $bugPattern->ShortDescription;
            $details = (string) $bugPattern->Details;

            $bugDescData[] = array( $bugPatternName => array(
                'description' => $description,
                'details' => $details
            ));
        }
        return $bugDescData;
    }

    private function loadBugRankData(OutputInterface $output)
    {
        $bugRankData = array();
        $content = $this->getFileByUrl(self::BUGPATTERN_RANK_FILE, $output);

        if(empty($content)){
            throw new \RuntimeException('Cannot load Rank data for Bug Pattern');
        }

        foreach(preg_split("/((\r?\n)|(\r\n?))/", $content) as $line){
            if (!empty($line)){
                $lineArr = explode(' ', $line);
                $bugRankData[] = array($lineArr[2] => array($lineArr[1] , (int) $lineArr[0]));
                /**e.g.  'NP_ALWAYS_NULL' => array('BugPattern' , '-1')*/
            }
        }

        return $bugRankData;
    }

    private function getBugPatternRank($bugPatternName, $bugCategoryName, $bugKind, array $bugRankData, OutputInterface $output)
    {
        $rank = 0;
        foreach($bugRankData as $key => $array){
            if($key === $bugPatternName || $key === $bugCategoryName || $key === $bugKind){
                $rank += $array[1];
            }
        }
        /**Balance Value for Rank Caculation*/
        $rank += 2;
        return $rank;
    }

    private function getDefaultSeverityFromRank($rank)
    {
        switch (true) {
            case ($rank >= 1 && $rank <= 4):
                return Severity::CRITICAL;
            case ($rank >= 5 && $rank <= 9):
                return Severity::MAJOR;
            case ($rank >= 10 && $rank <= 14):
                return Severity::MINOR;
            case ($rank >= 15 && $rank <= 20):
                return Severity::INFO;
            default:
                return Severity::UNKNOWN;
        }
    }
    
    private function getLabelFromBugCategory($bugCategory)
    {
        switch ($bugCategory) {
            case "CORRECTNESS":
                return array('correctness' => 'Correctness');
            case "SECURITY":
                return array('security' => 'Security');
            case "BAD_PRACTICE":
                return array('best-practice' => 'Best Practice');
            case "STYLE":
                return array('coding-style' => 'Coding Style');
            case "PERFORMANCE":
                return array('performance' => 'Performance');
            case "MALICIOUS_CODE":
                return array('security' => 'Security', 'malicious-code' => 'Malicious Code');
            case "MT_CORRECTNESS":
                return array('multi-threading' => 'Multi Threading');
            case "I18N":
                return array('compatibility' => 'Compatibility');
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

    private function findCheck(array $specifications, $bugName)
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
            throw new \RuntimeException("Found more than one check matching runeName: " . $bugName . '(' . array_keys($checks) . ')');
        } elseif (count($checks) == 1) {
            reset($checks);
        }

        $check = $this->em->createQuery('SELECT c FROM CodeReviewBundle:Check c WHERE c.key = :key')
            ->setParameter('key', 'findbugs.' . $bugName)
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

    private function createCheck(array $bugPatternData, $bugName, OutputInterface $output)
    {
        $output->writeln('Creating new check: ' . $bugName);
        $desc = isset($bugPatternData[$bugName]['description']) ? $bugPatternData[$bugName]['description'] : $bugName;
        $check = new Check(ProgrammingLanguage::getInstance(ProgrammingLanguage::JAVA), 'findbugs.' . $bugName, $desc);

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

    private function getBugCategory($bugName)
    {
        if (false !== strpos($bugName, '.')) {
            return substr($bugName, 0, strpos($bugName, '.'));
        }

        return null;
    }
}