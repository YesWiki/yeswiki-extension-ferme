<?php

namespace YesWiki\Ferme;

use YesWiki\Core\YesWikiHandler;

class UpgradeSelectedWikiHandler extends YesWikiHandler
{
    public function run()
    {
        header('Content-Type: application/json');

        if (!$this->wiki->UserIsAdmin()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Admin rights required']);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'POST method required']);
            exit;
        }

        $wikiFolder = $_POST['wiki'] ?? '';

        // Validate: only allow simple folder names (no path separators or special chars)
        if (empty($wikiFolder) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $wikiFolder)) {
            echo json_encode(['success' => false, 'error' => 'Invalid wiki folder name']);
            exit;
        }

        $farmRootFolder = $this->wiki->config['yeswiki-farm-root-folder'] ?? '.';

        if ($farmRootFolder === '.') {
            $wikiPath = realpath(getcwd() . DIRECTORY_SEPARATOR . $wikiFolder);
        } else {
            $wikiPath = realpath(
                getcwd() . DIRECTORY_SEPARATOR
                . $farmRootFolder . DIRECTORY_SEPARATOR
                . $wikiFolder
            );
        }

        if (!$wikiPath || !is_dir($wikiPath)) {
            echo json_encode(['success' => false, 'error' => 'Wiki folder not found: ' . $wikiFolder]);
            exit;
        }

        // Prevent path traversal: ensure the resolved path stays within the expected root
        $expectedRoot = $farmRootFolder === '.'
            ? realpath(getcwd())
            : realpath(getcwd() . DIRECTORY_SEPARATOR . $farmRootFolder);

        if (!$expectedRoot || strpos($wikiPath, $expectedRoot) !== 0) {
            echo json_encode(['success' => false, 'error' => 'Path traversal detected']);
            exit;
        }

        $yeswicliPath = $wikiPath . DIRECTORY_SEPARATOR . 'yeswicli';
        if (!file_exists($yeswicliPath)) {
            $sourceYeswicli = getcwd() . DIRECTORY_SEPARATOR . 'yeswicli';
            if (!file_exists($sourceYeswicli)) {
                echo json_encode(['success' => false, 'error' => 'yeswicli not found in source wiki']);
                exit;
            }
            if (!copy($sourceYeswicli, $yeswicliPath)) {
                echo json_encode(['success' => false, 'error' => 'Could not copy yeswicli to wiki: ' . $wikiFolder]);
                exit;
            }
        }

        chmod($yeswicliPath, 0755);

        // Allow enough time for the upgrade command to complete
        set_time_limit(300);

        $currentDir = getcwd();
        chdir($wikiPath);

        $output = [];
        $returnCode = 0;
        exec('./yeswicli upgrade 2>&1', $output, $returnCode);

        chdir($currentDir);

        $outputStr = implode("\n", $output);

        if ($returnCode !== 0) {
            echo json_encode([
                'success' => false,
                'output' => $outputStr,
                'error' => 'Command exited with code: ' . $returnCode,
            ]);
        } else {
            echo json_encode(['success' => true, 'output' => $outputStr]);
        }
        exit;
    }
}
