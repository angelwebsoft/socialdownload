<?php
header('Content-Type: application/json');
set_time_limit(0);

// Ensure PATH includes common locations for python3 and ffmpeg
putenv("PATH=" . getenv("PATH") . ":/usr/local/bin:/opt/homebrew/bin:/opt/local/bin:/usr/bin:/bin");

// Define local binary path
$localBinary = __DIR__ . '/yt-dlp';
$executable = '';

// Check if local binary exists
if (file_exists($localBinary)) {
    // Look for python3
    // Explicitly check for Homebrew/Local python first (usually 3.10+)
    if (file_exists('/usr/local/bin/python3')) {
        $python = '/usr/local/bin/python3';
    } elseif (file_exists('/opt/homebrew/bin/python3')) {
        $python = '/opt/homebrew/bin/python3';
    } else {
        $python = shell_exec('which python3');
        $python = trim($python);
        if (empty($python)) {
            $python = 'python3';
        }
    }
    // Execute via python explicitly
    $executable = $python . ' ' . escapeshellarg($localBinary);
} else {
    // Fallback to system-wide yt-dlp
    $executable = 'yt-dlp';
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$url = $input['url'] ?? '';

if (empty($action)) {
    echo json_encode(['error' => 'No action provided']);
    exit;
}


// Find ffmpeg explicitly
$ffmpeg = '/usr/local/bin/ffmpeg';
if (!file_exists($ffmpeg)) {
    $ffmpeg = trim(shell_exec('which ffmpeg'));
}
$ffmpegArgs = '';
if (!empty($ffmpeg) && file_exists($ffmpeg)) {
    $ffmpegArgs = ' --ffmpeg-location ' . escapeshellarg($ffmpeg);
}


if ($action === 'check') {
    if (empty($url)) {
        echo json_encode(['error' => 'No URL provided']);
        exit;
    }

    // First try: simple command expecting separate stdout/stderr

    // We do NOT use 2>&1 here so we just get JSON key output
    $cmd = $executable . $ffmpegArgs . ' --dump-json --no-playlist ' . escapeshellarg($url);
    
    $output = shell_exec($cmd);
    $data = json_decode($output, true);

    if ($data) {
        echo json_encode([
            'success' => true,
            'title' => $data['title'],
            'thumbnail' => $data['thumbnail'],
            'duration' => $data['duration_string'] ?? 'N/A',
            'id' => $data['id'],
            'webpage_url' => $data['webpage_url']
        ]);
    } else {
        // Failed to parse. Re-run WITH stderr to capture the error details
        $cmdError = $cmd . ' 2>&1';
        $errorOutput = shell_exec($cmdError);
        
        // If output contains valid JSON mixed with text, try to extract it
        // sometimes non-fatal warnings bleed into stdout on some configs
        if (preg_match('/\{.*\}/s', $errorOutput, $matches)) {
            $data = json_decode($matches[0], true);
            if ($data) {
                 echo json_encode([
                    'success' => true,
                    'title' => $data['title'],
                    'thumbnail' => $data['thumbnail'],
                    'duration' => $data['duration_string'] ?? 'N/A',
                    'id' => $data['id'],
                    'webpage_url' => $data['webpage_url']
                ]);
                exit;
            }
        }

        echo json_encode(['error' => 'Could not parse video data. Details: ' . substr($errorOutput, 0, 500)]);
    }

} elseif ($action === 'download') {
    $id = $input['id'] ?? '';
    if (empty($id)) {
        echo json_encode(['error' => 'No video ID provided']);
        exit;
    }

    $videoUrl = "https://www.youtube.com/watch?v=" . $id;
    $downloadDir = __DIR__ . '/downloads/';
    
    if (!file_exists($downloadDir)) {
        mkdir($downloadDir, 0777, true);
    }

    // Cleanup any partial/previous files for this ID to ensure fresh start
    $existing = glob($downloadDir . $id . ".*");
    foreach ($existing as $f) {
        @unlink($f); // suppress errors
    }

    $outputTemplate = $downloadDir . $id . '.%(ext)s';
    
    // Fix for XAMPP macOS "DLL Hell" issues where it loads its own incompatible libraries
    // instead of system libraries for ffmpeg/python.
    $envFix = 'export DYLD_LIBRARY_PATH=""; ';

    // Command to download
    // Remove --recode-video to avoid silent files.
    // Allow bestaudio to be any format (webm/m4a)
    $cmd = $envFix . $executable . $ffmpegArgs . ' -f "bestvideo[ext=mp4]+bestaudio/best[ext=mp4]/best" --merge-output-format mp4 --force-overwrites --no-part -o ' . escapeshellarg($outputTemplate) . ' ' . escapeshellarg($videoUrl) . ' 2>&1';
    
    $cmdOutput = shell_exec($cmd);

    // Check for explicit SUCCESS
    // Logic: File exists AND we shouldn't have leftover parts if auto-merge worked
    $expectedFile = $downloadDir . $id . '.mp4';
    
    // Check for leftover parts indicating failure
    $partFiles = glob($downloadDir . $id . ".*");
    $hasParts = false;
    foreach ($partFiles as $pf) {
        if (strpos($pf, '.m4a') !== false || strpos(basename($pf), '.webm') !== false || strpos(basename($pf), '.opus') !== false || preg_match('/\.f\d+$/', basename($pf))) {
             // If we see part files, disregard the main file even if it exists
             $hasParts = true; 
        }
    }

    if (file_exists($expectedFile) && !$hasParts) {
        $filename = basename($expectedFile);
        echo json_encode([
            'success' => true,
            'download_url' => 'downloads/' . $filename
        ]);
        exit;
    }

    // IF we are here, auto-merge failed. Manual Rescue.
    $videoPart = null;
    $audioPart = null;
    
    foreach ($partFiles as $pf) {
        $ext = pathinfo($pf, PATHINFO_EXTENSION);
        // Video candidates (mp4)
        if ($ext === 'mp4' && $pf !== $expectedFile) {
            $videoPart = $pf;
        }
        // Audio candidates (m4a, webm, opus)
        if ($ext === 'm4a' || $ext === 'webm' || $ext === 'opus') {
            $audioPart = $pf;
        }
    }
    
    // If we have an 'expectedFile' but it was marked as having parts, maybe THAT is the video part?
    if (!$videoPart && file_exists($expectedFile)) {
        $videoPart = $expectedFile;
    }

    if ($videoPart && $audioPart && file_exists($ffmpeg)) {
        // Safe Manual Merge: Output to temp file
        $tempMergedFile = $downloadDir . 'temp_' . $id . '.mp4';
        if (file_exists($tempMergedFile)) @unlink($tempMergedFile);

        // Determine audio strategy
        // If m4a, it's likely aac, can copy.
        // If webm/opus, need to transcode to aac.
        $audioExt = pathinfo($audioPart, PATHINFO_EXTENSION);
        $audioCodec = 'aac'; // Default fallback
        if ($audioExt === 'm4a' || $audioExt === 'mp4') {
            $audioCodec = 'copy';
        }

        // Build command - Remove strict experimental as it's often unnecessary for aac now
        // Prepend envFix to fix library paths
        $manualMergeCmd = $envFix . escapeshellcmd($ffmpeg) . ' -y -i ' . escapeshellarg($videoPart) . ' -i ' . escapeshellarg($audioPart) . ' -c:v copy -c:a ' . $audioCodec . ' ' . escapeshellarg($tempMergedFile) . ' 2>&1';
        $mergeOutput = shell_exec($manualMergeCmd);
        
        if (file_exists($tempMergedFile) && filesize($tempMergedFile) > 0) {
             // Cleanup parts
             @unlink($videoPart);
             @unlink($audioPart);
             
             // Move temp to final
             if (file_exists($expectedFile)) @unlink($expectedFile);
             
             if (copy($tempMergedFile, $expectedFile)) {
                 @unlink($tempMergedFile);
                 $filename = basename($expectedFile);
                 echo json_encode([
                    'success' => true,
                    'download_url' => 'downloads/' . $filename
                ]);
                exit;
             }
        }
        
        // If we reached here, merge failed.
        // Include DEBUG info in the error message so user sees it.
        $debugShort = substr($mergeOutput, -300); // Last 300 chars
        echo json_encode([
            'error' => 'Manual merge failed. FFmpeg output: ' . $debugShort
        ]);
        exit;
    }

    // Fallback: Just give them whatever we found that is playable
    if (!empty($partFiles)) {
        // Sort by size desc to hopefully get the video file
        usort($partFiles, function($a, $b) {
            return filesize($b) - filesize($a);
        });

        $file = $partFiles[0];
        $filename = basename($file);
        
        echo json_encode([
            'success' => true,
            'download_url' => 'downloads/' . $filename,
            'warning' => 'Could not merge streams. Returning largest available file.'
        ]);
    } else {
         echo json_encode(['error' => 'Download failed completely. Logs: ' . substr($cmdOutput, -1000)]);
    }
}
?>
