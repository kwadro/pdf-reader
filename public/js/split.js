(() => {
    const root = document.querySelector('.split-page');
    if (!root) {
        return;
    }

    const apiUrl = root.dataset.apiUrl;
    const maxBytes = Number(root.dataset.maxBytes || 0);
    const tokenKey = 'pdfreader.accessToken';

    const form = document.getElementById('split-form');
    const tokenInput = document.getElementById('access-token');
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('file-input');
    const dropzoneIdle = document.getElementById('dropzone-idle');
    const dropzoneFile = document.getElementById('dropzone-file');
    const fileNameEl = document.getElementById('file-name');
    const fileSizeEl = document.getElementById('file-size');
    const clearFileBtn = document.getElementById('clear-file');
    const splitBtn = document.getElementById('split-btn');
    const errorEl = document.getElementById('form-error');
    const uploadBlock = document.getElementById('upload-progress-block');
    const uploadPercent = document.getElementById('upload-percent');
    const uploadBar = document.getElementById('upload-bar');
    const uploadFill = document.getElementById('upload-fill');
    const processBlock = document.getElementById('process-block');
    const resultBlock = document.getElementById('result-block');
    const resultMeta = document.getElementById('result-meta');
    const downloadLink = document.getElementById('download-link');
    const downloadUrlText = document.getElementById('download-url-text');

    let selectedFile = null;
    let xhr = null;

    const savedToken = localStorage.getItem(tokenKey) || localStorage.getItem('pdfreader.split.accessToken');
    if (savedToken) {
        tokenInput.value = savedToken;
    }

    const formatBytes = (bytes) => {
        if (bytes < 1024) {
            return `${bytes} B`;
        }
        if (bytes < 1024 * 1024) {
            return `${(bytes / 1024).toFixed(1)} KB`;
        }
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    };

    const setHidden = (el, hidden) => {
        el.classList.toggle('is-hidden', hidden);
    };

    const showError = (message) => {
        errorEl.textContent = message;
        setHidden(errorEl, !message);
    };

    const resetResult = () => {
        setHidden(resultBlock, true);
        downloadLink.removeAttribute('href');
        downloadUrlText.removeAttribute('href');
        downloadUrlText.textContent = '';
        resultMeta.textContent = '';
    };

    const resetProgress = () => {
        setHidden(uploadBlock, true);
        setHidden(processBlock, true);
        uploadFill.style.width = '0%';
        uploadPercent.textContent = '0%';
        uploadBar.setAttribute('aria-valuenow', '0');
    };

    const updateSplitEnabled = () => {
        splitBtn.disabled = !(selectedFile && tokenInput.value.trim());
    };

    const isPdf = (file) => {
        const name = (file.name || '').toLowerCase();
        return file.type === 'application/pdf' || file.type === 'application/x-pdf' || name.endsWith('.pdf');
    };

    const setFile = (file) => {
        showError('');
        resetResult();
        resetProgress();

        if (!file) {
            selectedFile = null;
            fileInput.value = '';
            setHidden(dropzoneIdle, false);
            setHidden(dropzoneFile, true);
            updateSplitEnabled();
            return;
        }

        if (!isPdf(file)) {
            showError('Please choose a PDF file.');
            return;
        }

        if (maxBytes > 0 && file.size > maxBytes) {
            showError(`File is too large. Maximum size is ${formatBytes(maxBytes)}.`);
            return;
        }

        selectedFile = file;
        fileNameEl.textContent = file.name;
        fileSizeEl.textContent = formatBytes(file.size);
        setHidden(dropzoneIdle, true);
        setHidden(dropzoneFile, false);
        updateSplitEnabled();
    };

    const setBusy = (busy) => {
        splitBtn.disabled = busy || !(selectedFile && tokenInput.value.trim());
        splitBtn.textContent = busy ? 'Working…' : 'Split';
        tokenInput.disabled = busy;
        dropzone.classList.toggle('is-disabled', busy);
        if (busy) {
            dropzone.setAttribute('aria-disabled', 'true');
        } else {
            dropzone.removeAttribute('aria-disabled');
            updateSplitEnabled();
        }
    };

    const setUploadProgress = (loaded, total) => {
        const pct = total > 0 ? Math.min(100, Math.round((loaded / total) * 100)) : 0;
        uploadFill.style.width = `${pct}%`;
        uploadPercent.textContent = `${pct}%`;
        uploadBar.setAttribute('aria-valuenow', String(pct));

        if (pct >= 100) {
            setHidden(uploadBlock, true);
            setHidden(processBlock, false);
        }
    };

    dropzone.addEventListener('click', (event) => {
        if (event.target === clearFileBtn || dropzone.getAttribute('aria-disabled') === 'true') {
            return;
        }
        fileInput.click();
    });

    dropzone.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            if (dropzone.getAttribute('aria-disabled') !== 'true') {
                fileInput.click();
            }
        }
    });

    clearFileBtn.addEventListener('click', (event) => {
        event.stopPropagation();
        if (xhr) {
            xhr.abort();
            xhr = null;
        }
        setBusy(false);
        setFile(null);
    });

    fileInput.addEventListener('change', () => {
        const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
        setFile(file);
    });

    ['dragenter', 'dragover'].forEach((type) => {
        dropzone.addEventListener(type, (event) => {
            event.preventDefault();
            event.stopPropagation();
            if (dropzone.getAttribute('aria-disabled') !== 'true') {
                dropzone.classList.add('is-dragover');
            }
        });
    });

    ['dragleave', 'drop'].forEach((type) => {
        dropzone.addEventListener(type, (event) => {
            event.preventDefault();
            event.stopPropagation();
            dropzone.classList.remove('is-dragover');
        });
    });

    dropzone.addEventListener('drop', (event) => {
        if (dropzone.getAttribute('aria-disabled') === 'true') {
            return;
        }
        const file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0]
            ? event.dataTransfer.files[0]
            : null;
        setFile(file);
    });

    tokenInput.addEventListener('input', () => {
        localStorage.setItem(tokenKey, tokenInput.value.trim());
        updateSplitEnabled();
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        showError('');
        resetResult();

        const token = tokenInput.value.trim();
        if (!token) {
            showError('Access token is required.');
            updateSplitEnabled();
            return;
        }
        if (!selectedFile) {
            showError('Choose a PDF file first.');
            return;
        }

        localStorage.setItem(tokenKey, token);

        const body = new FormData();
        body.append('file', selectedFile, selectedFile.name);
        // UI mode: when pages > 100, response ZIP contains chunk archives of 100 pages.
        body.append('bundle_chunks', '1');

        setBusy(true);
        resetProgress();
        setHidden(uploadBlock, false);
        setHidden(processBlock, true);
        setUploadProgress(0, selectedFile.size || 1);

        xhr = new XMLHttpRequest();
        xhr.open('POST', apiUrl, true);
        xhr.setRequestHeader('X-Access-Token', token);
        xhr.responseType = 'json';

        xhr.upload.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                setUploadProgress(e.loaded, e.total);
            }
        });

        xhr.upload.addEventListener('load', () => {
            setUploadProgress(1, 1);
        });

        xhr.addEventListener('load', () => {
            setBusy(false);
            setHidden(uploadBlock, true);
            setHidden(processBlock, true);

            const data = xhr.response;
            if (xhr.status >= 200 && xhr.status < 300 && data && data.download_url) {
                const parts = [];
                const filename = data.filename || 'archive.zip';
                parts.push(filename);
                if (data.pages != null) {
                    parts.push(`${data.pages} page${data.pages === 1 ? '' : 's'}`);
                }
                if (data.archives != null && data.archives > 1) {
                    parts.push(`${data.archives} zip archives`);
                }
                resultMeta.textContent = parts.join(' · ');
                downloadLink.href = data.download_url;
                downloadLink.download = filename;
                downloadUrlText.href = data.download_url;
                downloadUrlText.textContent = data.download_url;
                setHidden(resultBlock, false);
                return;
            }

            const message = (data && (data.message || data.error))
                || `Request failed (${xhr.status}).`;
            showError(message);
        });

        xhr.addEventListener('error', () => {
            setBusy(false);
            setHidden(uploadBlock, true);
            setHidden(processBlock, true);
            showError('Network error. Please try again.');
        });

        xhr.addEventListener('abort', () => {
            setBusy(false);
            setHidden(uploadBlock, true);
            setHidden(processBlock, true);
        });

        xhr.send(body);
    });

    updateSplitEnabled();
})();
