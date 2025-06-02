<?php
// This script deduplicates leads from a JSON file based on their IDs or emails, 
// keeping the most recent entry based on the entry date.

/**
 * Loads leads from a JSON file.
 */
function loadLeads($filename): array {
    $data = json_decode(file_get_contents($filename), true);
    return $data['leads'] ?? [];
}

/**
 * Saves the deduplicated leads or change log to a JSON file.
 *
 * @param string $filename The name of the file to save.
 * @param array $data The data to save.
 * @param string $rootKey The root key for the JSON structure.
 */
function saveJson($filename, $data, $rootKey = 'leads'): void {
    file_put_contents($filename, json_encode([$rootKey => $data], JSON_PRETTY_PRINT));
}

/**
 * Parses a date string into a DateTime object.
 *
 * @param string $dateStr The date string to parse.
 * @return DateTime The parsed date.
 */
function parseDate(string $dateStr): DateTime {
    return new DateTime($dateStr);
}

/**
 * Deduplicates leads based on their IDs or emails, keeping the most recent entry.
 *
 * @param array $leads The array of leads to deduplicate.
 * @return array An array containing the deduplicated leads and a change log.
 */
function deduplicateLeads(array $leads): array {
    $byId = [];
    $byEmail = [];
    $log = [];

    foreach ($leads as $index => $lead) {
        $lead['_originalIndex'] = $index;
        $id = $lead['_id'];
        $email = $lead['email'];

        $existing = $byId[$id] ?? $byEmail[$email] ?? null;

        if ($existing) {
            $newDate = parseDate($lead['entryDate']);
            $oldDate = parseDate($existing['entryDate']);

            $isNewer = $newDate > $oldDate || ($newDate == $oldDate && $index > $existing['_originalIndex']);

            if ($isNewer) {
                $changes = [];
                foreach ($lead as $field => $value) {
                    if ($field !== '_originalIndex' && ($existing[$field] ?? null) !== $value) {
                        $changes[$field] = ['from' => $existing[$field] ?? null, 'to' => $value];
                    }
                }

                if ($changes) {
                    $log[] = [
                        'replaced_record' => $existing,
                        'new_record' => $lead,
                        'changes' => $changes
                    ];
                }

                $byId[$id] = $lead;
                $byEmail[$email] = $lead;
            }
        } else {
            $byId[$id] = $lead;
            $byEmail[$email] = $lead;
        }
    }

    // Final unique leads from $byId
    $seen = [];
    $uniqueLeads = [];

    foreach ($byId as $lead) {
        unset($lead['_originalIndex']);
        $hash = md5(json_encode($lead));
        if (!isset($seen[$hash])) {
            $uniqueLeads[] = $lead;
            $seen[$hash] = true;
        }
    }

    return [$uniqueLeads, $log];
}


/**
 * Main function to run the deduplication process.
 *
 * @param array $argv The command line arguments.
 */
function main($argv): void {
    if (count($argv) !== 4) {
        echo "Usage: php deduplicate_leads.php leads.json deduped_leads.json changes_log.json\n";
        exit(1);
    }

    [$inputFile, $outputFile, $logFile] = array_slice($argv, 1);
    [$deduped, $log] = deduplicateLeads(loadLeads($inputFile));

    saveJson($outputFile, $deduped);
    saveJson($logFile, $log, 'changes');

    echo "Deduplication complete: " . count($deduped) . " unique records saved.\n";
}

// Run the main function with command line arguments
main($argv);
