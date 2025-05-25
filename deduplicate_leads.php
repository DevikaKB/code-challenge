<?php
// This script deduplicates leads from a JSON file based on their IDs or emails, 
// keeping the most recent entry based on the entry date.

/**
 * Loads leads from a JSON file.
 */
function loadLeads($filename) {
    $json = file_get_contents($filename);
    $data = json_decode($json, true);
    return $data['leads'];
}

/**
 * Saves the deduplicated leads or change log to a JSON file.
 *
 * @param string $filename The name of the file to save.
 * @param array $data The data to save.
 * @param string $rootKey The root key for the JSON structure.
 */
function saveJson($filename, $data, $rootKey = 'leads') {
    file_put_contents($filename, json_encode([$rootKey => $data], JSON_PRETTY_PRINT));
}

/**
 * Parses a date string into a DateTime object.
 *
 * @param string $dateStr The date string to parse.
 * @return DateTime The parsed date.
 */
function parseDate($dateStr) {
    return new DateTime($dateStr);
}

/**
 * Deduplicates leads based on _id and email, keeping the most recent entry.
 *
 * @param array $leads The array of leads to deduplicate.
 * @return array An array containing the deduplicated leads and a change log.
 */
function deduplicateLeads($leads) {
    $seen = [];
    $deduped = [];
    $log = [];

    foreach ($leads as $index => $lead) {
        $key = null;
        $existingIndex = null;

        // Check by _id or email
        foreach ($seen as $k => $value) {
            if ($value['_id'] === $lead['_id'] || $value['email'] === $lead['email']) {
                $key = $k;
                $existingIndex = $k;
                break;
            }
        }

        if ($existingIndex !== null) {
            $existingLead = $deduped[$existingIndex];
            $existingDate = parseDate($existingLead['entryDate']);
            $newDate = parseDate($lead['entryDate']);

            $replace = false;

            if ($newDate > $existingDate) {
                $replace = true;
            } elseif ($newDate == $existingDate) {
                $replace = true;
            }

            if ($replace) {
                // Log changes
                $changes = [];
                foreach ($lead as $field => $value) {
                    if (!array_key_exists($field, $existingLead) || $existingLead[$field] !== $value) {
                        $changes[$field] = [
                            'from' => $existingLead[$field] ?? null,
                            'to' => $value
                        ];
                    }
                }

                $log[] = [
                    'replaced_record' => $existingLead,
                    'new_record' => $lead,
                    'changes' => $changes
                ];

                // Replace in deduped
                $deduped[$existingIndex] = $lead;
                $seen[$existingIndex] = $lead;
            }
        } else {
            $deduped[] = $lead;
            $seen[count($deduped) - 1] = $lead;
        }
    }

    return [$deduped, $log];
}

/**
 * Main function to run the deduplication process.
 *
 * @param array $argv The command line arguments.
 */
function main($argv) {
    if (count($argv) != 4) {
        echo "Usage: php deduplicate_leads.php leads.json output.json log.json\n";
        exit(1);
    }

    [$inputFile, $outputFile, $logFile] = array_slice($argv, 1);
    $leads = loadLeads($inputFile);
    [$dedupedLeads, $changeLog] = deduplicateLeads($leads);

    saveJson($outputFile, $dedupedLeads);
    saveJson($logFile, $changeLog, 'changes');

    echo "Deduplication complete.\n";
}

// Run the main function with command line arguments
main($argv);
