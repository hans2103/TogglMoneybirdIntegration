<?php

namespace TogglMoneybird;

use AJT\Toggl\TogglClient;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Dumper;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Console;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\ChoiceQuestion;

class IntegrateCommand extends Command
{
    const CONFIG_FILE = 'config.yml';
    const DEBUG_MODE = false;
    const TIMESTAMP_FORMAT = 'd-m-Y';
    const EU_COUNTRIES = array(
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GB', 'GR', 'HU',
        'HR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'
    );

    protected function configure()
    {
        $this
            ->setName('integrate')
            ->setDescription('Create Moneybird invoice from Toggl entries')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->_input = $input;
        $this->_output = $output;
        $this->_questionHelper = $this->getHelper('question');

        $this->_config = $this->getConfigValues();
        $this->_toggl = $this->getTogglApi();
        $this->_moneybird = $this->getMoneybirdApi();

        /* Choose Toggl workspace */
        $workspaceId = $this->getTogglWorkspace();

        /* Choose Toggl project */
        list($projectName, $projectId) = $this->getTogglProject($workspaceId);

        /* Choose date range for Toggl time entries */
        list($dateTo,$dateFrom) = $this->getTogglDateRange();

        /* Choose which time entries to add to the invoice */
        $chosenTimeEntries = $this->getTogglTimeEntries($dateTo,$dateFrom,$projectId);

        /* Choose Moneybird contact to invoice to */
        $moneybirdContact = $this->getMoneybirdContact();

        /* Find existing concepts for this contact */
        $conceptInvoiceId = $this->getMoneybirdConceptInvoice($moneybirdContact);

        /* If there is a concept invoice chosen, add the lines to that invoice. Otherwise, create new invoice */
        if($conceptInvoiceId) {
            $invoiceId = $this->addToExistingMoneybirdInvoice($moneybirdContact, $conceptInvoiceId, $chosenTimeEntries, $dateTo, $dateFrom);
        } else {
            $invoiceId = $this->createMoneybirdInvoice($moneybirdContact, $chosenTimeEntries, $dateTo, $dateFrom);
        }

        /* If the lines have succesfully been added, tag the chosen time entries as billed */
        if($invoiceId) {
            $this->tagTogglTimeEntries($chosenTimeEntries, 'billed');
        }
    }

    private function createMoneybirdInvoice($moneybirdContact, $chosenTimeEntries, $dateTo, $dateFrom)
    {
        $invoice = $this->_moneybird->salesInvoice();

        $invoice->{'contact_id'} = $moneybirdContact['id'];

        $moneybirdInvoiceLines = array();
        foreach($chosenTimeEntries as $timeEntry) {
            $invoiceLine = $this->_moneybird->salesInvoiceDetail();
            list($description,$amount) = explode(' - duration: ', $timeEntry);
            $invoiceLine->description = $description;
            $invoiceLine->amount = $this->roundTime($amount);
            $invoiceLine->price = $this->_config['hourly_rate'];
            $invoiceLine->period = date('Ymd', strtotime($dateFrom)) . '..' . date('Ymd', strtotime($dateTo));

            if($taxRateId = $this->fetchTaxRateId($moneybirdContact['object'])) {
                $invoiceLine->tax_rate_id = $taxRateId;
            }

            $moneybirdInvoiceLines[] = $invoiceLine;
        }

        $invoice->details = $moneybirdInvoiceLines;

        try {
            $invoice->save();
            $url = $invoice->url;
            $urlParts = explode('/', $url);
            $urlParts = array_slice($urlParts,0,-2);
            $url = implode('/', $urlParts) . '/' . $invoice->id;
            $this->_output->writeln('<info>Invoice succesfully saved: ' . $url . '</info>');
        } catch (Exception $e) {
            die('Could not set invoice: ' . $e->getMessage());
        }

        return $invoice->id;
    }

    private function addToExistingMoneybirdInvoice($moneybirdContact, $conceptInvoiceId, $chosenTimeEntries, $dateTo, $dateFrom)
    {
        $conceptInvoice = $this->_moneybird->salesInvoice()->find($conceptInvoiceId);

        // It is not possible to add items to an existing invoice so we'll create a new invoice and delete the old one
        $moneybirdInvoiceLines = array();

        // Add new lines
        foreach($chosenTimeEntries as $timeEntry) {
            $invoiceLine = $this->_moneybird->salesInvoiceDetail();
            list($description,$amount) = explode(' - duration: ', $timeEntry);
            list($amount,) = explode(' ', $amount);
            $invoiceLine->description = $description;
            $invoiceLine->amount = $this->roundTime($amount);
            $invoiceLine->price = $this->_config['hourly_rate'];

            if($taxRateId = $this->fetchTaxRateId($moneybirdContact['object'])) {
                $invoiceLine->tax_rate_id = $taxRateId;
            }

            $moneybirdInvoiceLines[] = $invoiceLine;
        }

        // Add existing lines
        foreach($conceptInvoice->details as $detail) {
            $moneybirdInvoiceLines[] = $detail;
        }

        foreach($moneybirdInvoiceLines as $invoiceLine) {
            if (isset($invoiceLine->period) && strlen($invoiceLine->period) > 0) {
                // If a period is already set on the existing invoice, update the 'to' field
                list($from,) = explode('..', $invoiceLine->period);
                $period = $from . '..' . date('Ymd', strtotime($dateTo));
            } else {
                $period = date('Ymd', strtotime($dateFrom)) . '..' . date('Ymd', strtotime($dateTo));
            }
            $invoiceLine->period = date('Ymd', strtotime($dateFrom)) . '..' . date('Ymd', strtotime($dateTo));
        }

        $invoice = $this->_moneybird->salesInvoice();
        $invoice->{'contact_id'} = $moneybirdContact['id'];
        $invoice->details = $moneybirdInvoiceLines;

        try {
            $invoice->save();
            $conceptInvoice->delete();
            $url = $invoice->url;
            $urlParts = explode('/', $url);
            $urlParts = array_slice($urlParts,0,-2);
            $url = implode('/', $urlParts) . '/' . $invoice->id;
            $this->_output->writeln('<info>Invoice succesfully saved: ' . $url . '</info>');
        } catch (Exception $e) {
            die('Could not set invoice: ' . $e->getMessage());
        }

        return $invoice->id;
    }

    private function tagTogglTimeEntries($timeEntries, $tag)
    {
        $timeEntryIds = array_keys($timeEntries);
        foreach($this->timeEntriesResults as $timeEntry) {
            if(!in_array($timeEntry['id'], $timeEntryIds)) {
                continue;
            }
            if(!isset($timeEntry['tags']) || !in_array($tag, $timeEntry['tags'])) {
                if(isset($timeEntry['tags'])) {
                    $tags = array_merge($timeEntry['tags'], array($tag));
                } else {
                    $tags = array($tag);
                }

                $timeEntry['tags'] = $tags;
                $timeEntry['created_with'] = 'TogglMoneybirdIntegration';

                $this->_toggl->UpdateTimeEntry(array(
                    'id' => $timeEntry['id'],
                    'time_entry' => $timeEntry
                ));
            }
        }
    }

    private function roundTime($input)
    {
        $roundMinutes = $this->_config['round_to'];
        if(!$roundMinutes) {
            return $input;
        } else {
            $time = strtotime(substr($input,0,5));
            $round = $roundMinutes*60;
            $rounded = round($time / $round) * $round;
            return date("H:i", $rounded);
        }
    }

    private function fetchTaxRateId($moneybirdContactObject)
    {
        if($moneybirdContactObject->country != 'NL') {
            if (
                in_array($moneybirdContactObject->country, self::EU_COUNTRIES)
                && isset($this->_config['moneybird_vat_inside_eu'])
            ) {
                return $this->_config['moneybird_vat_inside_eu'];
            } elseif (
                !in_array($moneybirdContactObject->country, self::EU_COUNTRIES)
                && isset($this->_config['moneybird_vat_outside_eu'])
            ) {
                return $this->_config['moneybird_vat_outside_eu'];
            }
        }

        return false;
    }

    private function getMoneybirdConceptInvoice($moneybirdContact)
    {
        $conceptInvoicesResults = $this->_moneybird->salesInvoice()->filter(array('state' => 'draft', 'contact_id' => $moneybirdContact['id']));
        if(count($conceptInvoicesResults) > 0) {
            $conceptInvoices[0] = 'No';
            foreach($conceptInvoicesResults as $conceptInvoicesResult) {
                $conceptInvoices[$conceptInvoicesResult->id] = 'Concept invoice with total of ' . $conceptInvoicesResult->total_price_incl_tax . ' (http://moneybird.com/' . $this->_config['moneybird_administration_id'] . '/sales_invoices/' . $conceptInvoicesResult->id . ')';
            }

            $question = new ChoiceQuestion(
                '<question>Do you want to add the entries to an existing concept invoice for this contact?</question> [No]',
                array_values($conceptInvoices),
                0,
                'No'
            );
            $question->setErrorMessage('Input is invalid.');

            $conceptInvoice = $this->_questionHelper->ask($this->_input, $this->_output, $question);
            if($conceptInvoice != 'No') {
                foreach ($conceptInvoicesResults as $conceptInvoicesResult) {
                    $title = 'Concept invoice with total of ' . $conceptInvoicesResult->total_price_incl_tax . ' (http://moneybird.com/' . $this->_config['moneybird_administration_id'] . '/sales_invoices/' . $conceptInvoicesResult->id . ')';
                    if($title == $conceptInvoice) {
                        $this->_output->writeln('<comment>The time entries are added to the existing concept invoice.</comment>');
                        $conceptInvoiceId = $conceptInvoicesResult->id;
                        return $conceptInvoiceId;
                    }
                }
            }
        }

        return false;
    }

    private function getTogglWorkspace() {
        $workspacesResults = $this->_toggl->getWorkspaces(array());

        $workspaceId = false;
        if(count($workspacesResults)==1) {
            $workspace = array_pop($workspacesResults);
            $workspaceId = $workspace['id'];
        } elseif(count($workspacesResults) > 1) {
            $workspaces = array();
            foreach ($workspacesResults as $workspaceResult) {
                $workspaces[$workspaceResult['id']] = $workspaceResult['name'];
            }

            $question = new ChoiceQuestion(
                '<question>Choose which Toggl workspace you want to use.</question>',
                array_values($workspaces),
                0
            );
            $question->setErrorMessage('Workspace is invalid.');

            $workspace = $this->_questionHelper->ask($this->_input, $this->_output, $question);
            $this->_output->writeln('<comment>You have just selected workspace: ' . $workspace . '</comment>');

            foreach ($workspacesResults as $workspaceResult) {
                if($workspaceResult['name'] == $workspace) {
                    $workspaceId = $workspaceResult['id'];
                    break;
                }
            }
        }

        if(!$workspaceId) {
            die('No workspace(s) found');
        }

        return $workspaceId;
    }

    private function getTogglProject($workspaceId) {
        $projectsResults = $this->_toggl->getProjects(array('id' => $workspaceId));
        $projects = array();
        foreach($projectsResults as $projectResult) {
            $projects[$projectResult['id']] = $projectResult['name'];
        }

        $question = new ChoiceQuestion(
            '<question>Choose which project you want to find entries for.</question>',
            array_values($projects),
            0
        );
        $question->setAutocompleterValues(array_values($projects));
        $question->setErrorMessage('Project is invalid.');

        $project = $this->_questionHelper->ask($this->_input, $this->_output, $question);
        $this->_output->writeln('<comment>You have just selected project: ' . $project . '</comment>');
        
        $projectId = array_search($project, $projects);

        return array($project,$projectId);
    }

    private function getTogglDateRange() {
        $dateFromDefault = date(self::TIMESTAMP_FORMAT, strtotime('-1 month'));
        $question = new Question('<question>From which date do you want to find entries?</question> [' . $dateFromDefault . '] ', $dateFromDefault);
        $question->setValidator(function ($answer) {
            if (date(self::TIMESTAMP_FORMAT, strtotime($answer)) != $answer) {
                throw new \RuntimeException(
                    'Input format should be ' . self::TIMESTAMP_FORMAT
                );
            }

            return $answer;
        });
        $dateFrom = $this->_questionHelper->ask($this->_input, $this->_output, $question);

        $dateToDefault = date(self::TIMESTAMP_FORMAT);
        $question = new Question('<question>Until which date do you want to find entries?</question> [' . $dateToDefault . '] ', $dateToDefault);
        $question->setValidator(function ($answer) {
            if (date(self::TIMESTAMP_FORMAT, strtotime($answer)) != $answer) {
                throw new \RuntimeException(
                    'Input format should be ' . self::TIMESTAMP_FORMAT
                );
            }

            return $answer;
        });
        $dateTo = $this->_questionHelper->ask($this->_input, $this->_output, $question);

        $this->_output->writeln('<comment>Looking for entries from ' . $dateFrom . ' to ' . $dateTo . '</comment>');

        $dateTo = date('c', strtotime($dateTo . ' 23:59'));
        $dateFrom = date('c', strtotime($dateFrom));

        return array($dateTo, $dateFrom);
    }

    private function getTogglTimeEntries($dateTo, $dateFrom, $projectId) {
        $this->timeEntriesResults = $this->_toggl->getTimeEntries(array(
            'start_date' => $dateFrom,
            'end_date' => $dateTo,
        ));

        $timeEntries = array();
        foreach($this->timeEntriesResults as $timeEntriesResult) {
            if(!isset($timeEntriesResult['pid']) || $timeEntriesResult['pid'] != $projectId) continue;
            $title = $timeEntriesResult['description'] . ' - duration: ' . gmdate('H:i:s', $timeEntriesResult['duration']);
            if(isset($timeEntriesResult['tags']) && count($timeEntriesResult['tags'])>0) {
                $title .= ' <info>' . implode(', ', $timeEntriesResult['tags']) . '</info>';
            }
            $timeEntries[$timeEntriesResult['id']] = $title;
        }

        $question = new ChoiceQuestion(
            '<question>Choose which time entries you want to invoice (comma separated numerical input).</question>',
            array_values($timeEntries),
            0
        );
        $question->setMultiselect(true);
        $question->setErrorMessage('Time entry input is invalid.');

        $timeEntryValues = $this->_questionHelper->ask($this->_input, $this->_output, $question);
        $chosenTimeEntries = array_intersect($timeEntries, $timeEntryValues);

        foreach($chosenTimeEntries as $chosenTimeEntry) {
            if(stripos($chosenTimeEntry, 'fix')!==false || stripos($chosenTimeEntry, 'bug')!==false) {
                $this->_output->writeln('<error>Caution; you are about to invoice a time entry that indicates it is a bug fix: ' . $chosenTimeEntry . '</error>');
            }
            if(stripos($chosenTimeEntry, 'billed')!==false) {
                $this->_output->writeln('<error>Caution; you are about to invoice a time entry with a \'billed\' tag: ' . $chosenTimeEntry . '</error>');
            }
        }

        return $chosenTimeEntries;
    }

    private function getMoneybirdContact()
    {
        $contactObjects = array();
        $contactIds = $this->_moneybird->contact()->listVersions();
        $chunks = array_chunk($contactIds,100,true);
        foreach($chunks as $chunk) {
            $ids = array();
            foreach($chunk as $contact) {
                $ids[] = $contact->id;
            }
            $contactsApiResults = $this->_moneybird->contact()->getVersions($ids);
            foreach($contactsApiResults as $contactApiResult) {
                if($contactApiResult->company_name) {
                    $name = $contactApiResult->company_name;
                    if(
                        isset($contactApiResult->firstname)
                        && strlen($contactApiResult->firstname)>0
                        && isset($contactApiResult->lastname)
                        && strlen($contactApiResult->lastname)>0
                    ) {
                        $name .= ' (' . $contactApiResult->firstname . ' ' . $contactApiResult->lastname . ')';
                    }
                } else {
                    $name = $contactApiResult->firstname . ' ' . $contactApiResult->lastname;
                }
                $contactObjects[$contactApiResult->id] = $contactApiResult;
                $contactsResults[$contactApiResult->id] = $name;
            }
        }

        $question = new ChoiceQuestion(
            '<question>Choose which contact you want to create the invoice for.</question>',
            array_unique(array_values($contactsResults)),
            0
        );
        $question->setErrorMessage('Contact is invalid.');
        $question->setAutocompleterValues($contactsResults);

        $contact = $this->_questionHelper->ask($this->_input, $this->_output, $question);
        $this->_output->writeln('<comment>You have just selected contact: ' . $contact . '</comment>');

        $contactId = false;
        foreach($contactsResults as $contactId=>$contactName) {
            if($contactName == $contact) {
                break;
            }
        }

        return array('name' => $contactName, 'id' => $contactId, 'object' => $contactObjects[$contactId]);
    }

    private function getConfigValues()
    {
        if (!file_exists('config.yml')) {
            $this->_output->writeln('<comment>' . self::CONFIG_FILE . ' does not exist. We will ask you for some inputs to create the configuration file.</comment>');

            $inputs = array(
                'toggl_token' => 'Toggl API token',
                'moneybird_administration_id' => 'Moneybird administration ID',
                'moneybird_access_token' => 'Moneybird access token',
                'hourly_rate' => 'Your hourly rate',
                'round_to' => '(optional) Round time entries to X minutes',
                'moneybird_vat_outside_eu' => '(optional) Moneybird tax rate ID for outside EU',
                'moneybird_vat_inside_eu' => '(optional) Moneybird tax rate ID for inside EU',
            );

            foreach($inputs as $field => $hint) {
                $question = new Question('<question>' . $hint . ':</question> ');
                $config[$field] = $this->_questionHelper->ask($this->_input, $this->_output, $question);
            }

            $dumper = new Dumper();
            $yaml = $dumper->dump($config);
            file_put_contents(self::CONFIG_FILE, $yaml);
        }

        if (file_exists('config.yml')) {
            try {
                $yaml = new Parser();
                return $yaml->parse(file_get_contents(self::CONFIG_FILE));
            } catch (ParseException $e) {
                printf("Unable to parse the YAML string: %s", $e->getMessage());
                die();
            }
        } else {
            die(self::CONFIG_FILE . ' does not exist. Please copy ' . self::CONFIG_FILE . '.example to ' . self::CONFIG_FILE . ' and fill the fields.');
        }
    }

    private function getTogglApi()
    {
        return TogglClient::factory(array('api_key' => $this->_config['toggl_token'], 'debug' => self::DEBUG_MODE));
    }

    private function getMoneybirdApi()
    {
        $connection = new \Picqer\Financials\Moneybird\Connection();
        $connection->setAccessToken($this->_config['moneybird_access_token']);
        $connection->setAdministrationId($this->_config['moneybird_administration_id']);
        $connection->setAuthorizationCode('not_required');

        try {
            $connection->connect();
        } catch (Exception $e) {
            die('Could not initialize Moneybird connection: ' . $e->getMessage());
        }

        $this->apiConnection = $connection;

        return new \Picqer\Financials\Moneybird\Moneybird($this->apiConnection);
    }
}