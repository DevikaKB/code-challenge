<?php
// This script deduplicates leads from a JSON file based on their IDs or emails, 
// keeping the most recent entry based on the entry date.

/**
 * Loads leads from a JSON file.
 */
function loadLeads($filename) {
    $json = file_get_contents($filename);
    $data = json_decode($json, true);
    return $data['leads'] ?? [];
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
 * Deduplicates leads based on their IDs or emails, keeping the most recent entry.
 *
 * @param array $leads The array of leads to deduplicate.
 * @return array An array containing the deduplicated leads and a change log.
 */
function deduplicateLeads(array $leads): array {
    $records = [];
    $log = [];

    foreach ($leads as $index => $lead) {
        $lead['_originalIndex'] = $index;

        // Unique key: _id or email
        $key = $lead['_id'];
        if (isset($records[$key])) {
            $existing = $records[$key];
        } else {
            // Check by email if _id not seen yet
            $emailKey = array_search($lead['email'], array_column($records, 'email'));
            $key = $emailKey !== false ? array_keys($records)[$emailKey] : $key;
            $existing = $records[$key] ?? null;
        }

        $replace = false;

        if ($existing) {
            $dateNew = parseDate($lead['entryDate']);
            $dateOld = parseDate($existing['entryDate']);

            if ($dateNew > $dateOld || ($dateNew == $dateOld && $lead['_originalIndex'] > $existing['_originalIndex'])) {
                $replace = true;
            }

            if ($replace) {
                $changes = [];

                foreach ($lead as $field => $value) {
                    if ($field === '_originalIndex') continue;
                    if (!array_key_exists($field, $existing) || $existing[$field] !== $value) {
                        $changes[$field] = [
                            'from' => $existing[$field] ?? null,
                            'to' => $value
                        ];
                    }
                }

                if ($changes) {
                    $log[] = [
                        'replaced_record' => $existing,
                        'new_record' => $lead,
                        'changes' => $changes
                    ];
                }

                $records[$key] = $lead;
            }
        } else {
            $records[$key] = $lead;
        }
    }

    // Remove helper fields before saving
    $deduped = array_map(fn($lead) => array_diff_key($lead, ['_originalIndex' => true]), $records);
    return [$deduped, $log];
}

/**
 * Main function to run the deduplication process.
 *
 * @param array $argv The command line arguments.
 */
function main($argv) {
    // Check for correct number of arguments
    if (count($argv) !== 4) {
        echo "Usage: php deduplicate_leads.php leads.json deduped_leads.json changes_log.json\n";
        exit(1);
    }

    [$inputFile, $outputFile, $logFile] = array_slice($argv, 1);
    $leads = loadLeads($inputFile);
    [$dedupedLeads, $changeLog] = deduplicateLeads($leads);

    saveJson($outputFile, $dedupedLeads);
    saveJson($logFile, $changeLog, 'changes');

    echo "Deduplication complete: " . count($dedupedLeads) . " unique records saved.\n";
}

// Run the main function with command line arguments
main($argv);
