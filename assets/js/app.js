const form = document.getElementById('downloadForm');
const urlInput = document.getElementById('url');
const submitBtn = document.getElementById('submitBtn');
const spinner = document.getElementById('loadingSpinner');
const btnText = submitBtn.querySelector('span');
const statusMsg = document.getElementById('statusMessage');
const videoPreview = document.getElementById('videoPreview');

let currentVideoId = null;

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const url = urlInput.value.trim();
    if (!url) return;

    setLoading(true);
    hideStatus();
    hidePreview();

    try {
        const response = await fetch('process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'check', url: url })
        });

        const data = await response.json();

        if (data.success) {
            showPreview(data);
        } else {
            showStatus(data.error || 'Something went wrong', 'error');
        }
    } catch (err) {
        showStatus('Network error or server unavailable', 'error');
        console.error(err);
    } finally {
        setLoading(false);
    }
});

function setLoading(isLoading) {
    if (isLoading) {
        spinner.style.display = 'block';
        btnText.style.display = 'none';
        submitBtn.disabled = true;
    } else {
        spinner.style.display = 'none';
        btnText.style.display = 'block';
        submitBtn.disabled = false;
    }
}

function showStatus(msg, type) {
    statusMsg.textContent = msg;
    statusMsg.className = 'status-message visible ' + type;
}

function hideStatus() {
    statusMsg.className = 'status-message';
}

function showPreview(data) {
    currentVideoId = data.id;
    videoPreview.innerHTML = `
        <img src="${data.thumbnail}" alt="Thumbnail" style="width:100%; display:block;">
        <div class="video-info">
            <div class="video-title">${data.title}</div>
            <p style="color: #cbd5e1; font-size: 0.9rem; margin-bottom: 1rem;">Duration: ${data.duration}</p>
            <div class="download-actions">
                <button onclick="downloadVideo()" class="btn-download" style="padding: 0.8rem; font-size: 1rem;">
                    Download MP4
                </button>
                <a href="${data.webpage_url}" target="_blank" class="action-btn">Watch on YouTube</a>
            </div>
        </div>
    `;
    videoPreview.classList.add('visible');
}

function hidePreview() {
    videoPreview.classList.remove('visible');
    videoPreview.innerHTML = '';
}

window.downloadVideo = async () => {
    if (!currentVideoId) return;
    
    const downloadBtn = videoPreview.querySelector('.btn-download');
    const originalText = downloadBtn.innerText;
    downloadBtn.innerText = 'Downloading...';
    downloadBtn.disabled = true;

    try {
        const response = await fetch('process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'download', id: currentVideoId })
        });

        const data = await response.json();

        if (data.success) {
            // Trigger download by opening the file URL
            // Create a temporary link to force download
            const link = document.createElement('a');
            link.href = data.download_url;
            link.download = ''; // Browser should handle filename
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            showStatus('Download started!', 'success');
        } else {
            showStatus(data.error || 'Download failed', 'error');
        }
    } catch (err) {
        showStatus('Server error during download', 'error');
    } finally {
        downloadBtn.innerText = originalText;
        downloadBtn.disabled = false;
    }
};
