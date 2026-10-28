<?php

declare(strict_types=1);

namespace SimpleKuma;

/**
 * Logger
 * Handles structured logging for cost sync operations and other app events
 */
class Logger
{
    private string $logDir;
    private string $logFile = '';
    private bool $fileLogging = true;
    private int $retentionDays = 7;

    public function __construct(?string $logDir = null)
    {
        // Logs stay outside the document root. If storage/logs cannot be
        // written, fall back to the PHP error log rather than public/logs.
        $defaultLogDir = dirname(__DIR__) . '/storage/logs';

        if ($logDir === null) {
            if (!is_dir($defaultLogDir)) {
                @mkdir($defaultLogDir, 0755, true);
            }
            $this->logDir = $defaultLogDir;
            $this->fileLogging = is_dir($defaultLogDir) && is_writable($defaultLogDir);
        } else {
            $this->logDir = $logDir;
        }

        if ($this->fileLogging) {
            $this->ensureLogDirectory();
            $this->logFile = $this->logDir . '/fb_cost_updater.log';
        }
    }

    /**
     * Ensure log directory exists
     */
    private function ensureLogDirectory(): void
    {
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * Log cost sync operation summary
     */
    public function logCostSyncRun(array $stats): void
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => 'cost_sync',
            'stats' => $stats
        ];
        
        $this->writeLog(json_encode($logEntry) . PHP_EOL);
        
        // Also write human-readable summary
        $summary = sprintf(
            "[%s] Cost Sync Summary: Integrations: %d, Adsets: %d processed (%d success, %d failed), Ads: %d processed (%d success, %d failed)",
            date('Y-m-d H:i:s'),
            $stats['integrations_processed'] ?? 0,
            $stats['adsets_processed'] ?? 0,
            $stats['adsets_success'] ?? 0,
            $stats['adsets_failed'] ?? 0,
            $stats['ads_processed'] ?? 0,
            $stats['ads_success'] ?? 0,
            $stats['ads_failed'] ?? 0
        );
        
        $this->writeLog($summary . PHP_EOL);
        
        // Log errors if any
        if (!empty($stats['errors'])) {
            foreach ($stats['errors'] as $error) {
                $this->writeLog(sprintf("[%s] ERROR: %s", date('Y-m-d H:i:s'), $error) . PHP_EOL);
            }
        }
    }

    /**
     * Log individual API call result
     */
    public function logApiCall(string $type, string $entityId, bool $success, ?string $error = null): void
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => 'api_call',
            'entity_type' => $type,
            'entity_id' => $entityId,
            'success' => $success,
            'error' => $error
        ];
        
        $this->writeLog(json_encode($logEntry) . PHP_EOL);
    }

    /**
     * Log detailed operation information for troubleshooting
     */
    public function logDetail(string $message, array $context = []): void
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'type' => 'detail',
            'message' => $message,
            'context' => $context
        ];
        
        // Write JSON entry
        $this->writeLog(json_encode($logEntry) . PHP_EOL);
        
        // Also write human-readable line
        $contextStr = !empty($context) ? ' | ' . json_encode($context) : '';
        $this->writeLog(sprintf("[%s] %s%s", date('Y-m-d H:i:s'), $message, $contextStr) . PHP_EOL);
    }

    /**
     * Write log entry to file
     */
    private function writeLog(string $message): void
    {
        if (!$this->fileLogging || $this->logFile === '') {
            error_log('SimpleKuma cost sync: ' . trim($message));
            return;
        }
        file_put_contents($this->logFile, $message, FILE_APPEND | LOCK_EX);
    }

    /**
     * Rotate logs older than retention period
     */
    public function rotateLogs(): void
    {
        if (!is_file($this->logFile)) {
            return;
        }
        
        $logData = file_get_contents($this->logFile);
        $lines = explode(PHP_EOL, $logData);
        
        $cutoffDate = date('Y-m-d', strtotime("-{$this->retentionDays} days"));
        $cutoffTimestamp = strtotime($cutoffDate);
        
        $filteredLines = [];
        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }
            
            // Try to extract timestamp from JSON or plain log format
            $timestamp = $this->extractTimestamp($line);
            if ($timestamp && $timestamp >= $cutoffTimestamp) {
                $filteredLines[] = $line;
            }
        }
        
        // Write filtered lines back
        file_put_contents($this->logFile, implode(PHP_EOL, $filteredLines) . PHP_EOL, LOCK_EX);
    }

    /**
     * Extract timestamp from log line
     */
    private function extractTimestamp(string $line): ?int
    {
        // Try JSON format first
        $data = json_decode($line, true);
        if ($data && isset($data['timestamp'])) {
            return strtotime($data['timestamp']);
        }
        
        // Try plain format: [YYYY-MM-DD HH:MM:SS]
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
            return strtotime($matches[1]);
        }
        
        return null;
    }

    /**
     * Get log entries for the last N days
     */
    public function getRecentLogs(int $days = 7): array
    {
        if (!is_file($this->logFile)) {
            return [];
        }
        
        $logData = file_get_contents($this->logFile);
        $lines = explode(PHP_EOL, $logData);
        
        $cutoffTimestamp = strtotime("-{$days} days");
        $entries = [];
        
        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }
            
            $timestamp = $this->extractTimestamp($line);
            if ($timestamp && $timestamp >= $cutoffTimestamp) {
                $entries[] = $line;
            }
        }
        
        return $entries;
    }
}


