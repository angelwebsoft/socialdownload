<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SocialDownload - Premium Video Downloader</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="background-animate"></div>
    <div class="container">
        <div class="glass-card">
            <div class="header">
                <h1>SocialDownload</h1>
                <p>Download YouTube videos in highest quality.</p>
            </div>

            <form id="downloadForm">
                <div class="input-group">
                    <div class="input-wrapper">
                        <input type="text" id="url" name="url" placeholder="Paste YouTube link here..." required autocomplete="off">
                        <svg class="url-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                        </svg>
                    </div>
                </div>

                <button type="submit" class="btn-download" id="submitBtn">
                    <span>Download Video</span>
                    <div class="spinner" id="loadingSpinner"></div>
                </button>
            </form>

            <div id="statusMessage" class="status-message"></div>

            <div id="videoPreview" class="video-preview">
                <!-- Content will be injected by JS -->
            </div>
        </div>
    </div>

    <script src="assets/js/app.js"></script>
</body>
</html>
