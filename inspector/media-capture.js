document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.upload-tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const tabName = this.getAttribute('data-tab');
            document.querySelectorAll('.upload-tab-btn').forEach(b => {
                b.classList.remove('active');
                b.style.background = 'transparent';
                b.classList.add('text-lgu-paragraph');
                b.classList.remove('text-lgu-headline');
            });
            this.classList.add('active');
            this.style.background = 'linear-gradient(135deg, rgba(250, 174, 43, 0.1), rgba(250, 174, 43, 0.05))';
            this.classList.add('text-lgu-headline');
            this.classList.remove('text-lgu-paragraph');
            document.querySelectorAll('.upload-tab-content').forEach(content => {
                content.classList.add('hidden');
            });
            document.getElementById(tabName + '-tab').classList.remove('hidden');
            if (tabName !== 'camera' && window.cameraStream) stopCamera();
            if (tabName !== 'video' && window.videoStream) {
                if (window.mediaRecorder && window.mediaRecorder.state === 'recording') stopRecording();
                window.videoStream.getTracks().forEach(track => track.stop());
                window.videoStream = null;
                const videoPreview = document.getElementById('videoPreview');
                if (videoPreview) videoPreview.srcObject = null;
                document.getElementById('startVideoBtn').classList.remove('hidden');
                document.getElementById('recordVideoBtn').classList.add('hidden');
                document.getElementById('stopVideoBtn').classList.add('hidden');
            }
        });
    });

    const startVideoBtn = document.getElementById('startVideoBtn');
    const recordVideoBtn = document.getElementById('recordVideoBtn');
    const stopVideoBtn = document.getElementById('stopVideoBtn');
    const videoPreview = document.getElementById('videoPreview');
    const recordedVideo = document.getElementById('recordedVideo');

    if (startVideoBtn) startVideoBtn.addEventListener('click', startVideoCamera);
    if (recordVideoBtn) recordVideoBtn.addEventListener('click', startRecording);
    if (stopVideoBtn) stopVideoBtn.addEventListener('click', stopRecording);

    const startCameraBtn = document.getElementById('startCameraBtn');
    const stopCameraBtn = document.getElementById('stopCameraBtn');
    const captureBtn = document.getElementById('captureBtn');
    const cameraVideo = document.getElementById('cameraVideo');
    const captureCanvas = document.getElementById('captureCanvas');
    const capturedImage = document.getElementById('capturedImage');

    if (startCameraBtn) startCameraBtn.addEventListener('click', startCamera);
    if (stopCameraBtn) stopCameraBtn.addEventListener('click', stopCamera);
    if (captureBtn) captureBtn.addEventListener('click', capturePhoto);

    document.getElementById('uploadBtn').addEventListener('click', (e) => {
        e.preventDefault();
        document.getElementById('mediaInput').click();
    });

    document.getElementById('mediaInput').addEventListener('change', function() {
        if (this.files.length > 0) {
            const file = this.files[0];
            const preview = file.type.startsWith('image/') ? document.createElement('img') : document.createElement('video');
            preview.src = URL.createObjectURL(file);
            preview.style.cssText = 'max-width:300px;max-height:300px;border-radius:8px;margin-top:10px;border:2px solid #faae2b;display:block';
            if (preview.tagName === 'VIDEO') preview.controls = true;
            const container = document.getElementById('mediaStatus').parentElement;
            const old = container.querySelector('img, video');
            if (old) old.remove();
            container.appendChild(preview);
            document.getElementById('mediaStatus').classList.remove('hidden');
        }
    });

    async function startVideoCamera() {
        try {
            if (window.videoStream) window.videoStream.getTracks().forEach(track => track.stop());
            const constraints = {
                video: { facingMode: 'environment' },
                audio: true
            };
            try {
                window.videoStream = await navigator.mediaDevices.getUserMedia(constraints);
            } catch (audioErr) {
                window.videoStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            }
            if (videoPreview) {
                videoPreview.srcObject = window.videoStream;
                videoPreview.muted = true;
                videoPreview.play().catch(e => console.warn('Play error:', e));
            }
            if (startVideoBtn) startVideoBtn.classList.add('hidden');
            if (recordVideoBtn) recordVideoBtn.classList.remove('hidden');
            Swal.fire({
                icon: 'success',
                title: 'Camera Started',
                text: 'Camera is now active. Click "Record" to start recording.',
                timer: 2000,
                showConfirmButton: false
            });
        } catch (err) {
            console.error('Video camera error:', err);
            Swal.fire({
                icon: 'error',
                title: 'Camera Error',
                text: 'Unable to access camera. Please check permissions and try again.',
                confirmButtonColor: '#fa5246'
            });
        }
    }

    function startRecording() {
        if (!window.videoStream) {
            Swal.fire({
                icon: 'warning',
                title: 'Camera Not Started',
                text: 'Please start the camera first before recording.',
                confirmButtonColor: '#faae2b'
            });
            return;
        }
        window.recordedChunks = [];
        const now = new Date();
        const options = {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        };
        window.videoTimestamp = now.toLocaleDateString('en-US', options);
        let options_recorder = null;
        const mimeTypes = ['video/webm', 'video/mp4', ''];
        for (const mimeType of mimeTypes) {
            if (!mimeType || MediaRecorder.isTypeSupported(mimeType)) {
                options_recorder = mimeType ? { mimeType } : undefined;
                break;
            }
        }
        try {
            window.mediaRecorder = new MediaRecorder(window.videoStream, options_recorder);
        } catch (e) {
            try {
                window.mediaRecorder = new MediaRecorder(window.videoStream);
            } catch (e2) {
                Swal.fire({
                    icon: 'error',
                    title: 'Recording Error',
                    text: 'Your device does not support video recording. Please try using the photo capture instead.',
                    confirmButtonColor: '#fa5246'
                });
                return;
            }
        }
        window.mediaRecorder.ondataavailable = (event) => {
            if (event.data.size > 0) window.recordedChunks.push(event.data);
        };
        window.mediaRecorder.onstop = () => {
            const timestamp = window.videoTimestamp || 'Unknown time';
            if (window.recordingTimer) clearInterval(window.recordingTimer);
            window.recordingTimer = null;
            const timerElement = document.getElementById('recordingTimer');
            if (timerElement) timerElement.classList.add('hidden');
            let mimeType = 'video/webm';
            if (window.mediaRecorder.mimeType) mimeType = window.mediaRecorder.mimeType.split(';')[0];
            let fileExt = 'webm';
            if (mimeType.includes('mp4')) fileExt = 'mp4';
            else if (mimeType.includes('quicktime')) fileExt = 'mov';
            else if (mimeType.includes('msvideo')) fileExt = 'avi';
            const blob = new Blob(window.recordedChunks, { type: mimeType });
            const file = new File([blob], `video_${Date.now()}.${fileExt}`, { 
                type: mimeType,
                lastModified: new Date().getTime()
            });
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            document.getElementById('mediaInput').files = dataTransfer.files;
            if (recordedVideo) {
                const videoURL = URL.createObjectURL(blob);
                recordedVideo.src = videoURL;
                recordedVideo.muted = false;
                recordedVideo.controls = true;
                recordedVideo.playsInline = true;
                recordedVideo.style.display = 'block';
                recordedVideo.classList.remove('hidden');
                setTimeout(() => recordedVideo.play().catch(e => console.warn('Play error:', e)), 100);
            }
            const videoTimestampElement = document.getElementById('videoTimestamp');
            const videoTimestampText = document.getElementById('videoTimestampText');
            if (videoTimestampElement && videoTimestampText) {
                videoTimestampText.textContent = timestamp;
                videoTimestampElement.classList.remove('hidden');
            }
            document.getElementById('mediaStatus').classList.remove('hidden');
            Swal.fire({
                icon: 'success',
                title: 'Video Recorded!',
                text: 'You can now play the video to review it before submitting.',
                timer: 3000,
                showConfirmButton: false
            });
        };
        window.mediaRecorder.onerror = (event) => {
            console.error('Recording error:', event.error);
            Swal.fire({
                icon: 'error',
                title: 'Recording Error',
                text: 'An error occurred during recording. Please try again.',
                confirmButtonColor: '#fa5246'
            });
        };
        window.mediaRecorder.start();
        recordVideoBtn.classList.add('hidden');
        stopVideoBtn.classList.remove('hidden');
        document.getElementById('recordingTimer').classList.remove('hidden');
        window.recordingStartTime = Date.now();
        window.recordingTimer = setInterval(() => {
            const elapsed = Math.floor((Date.now() - window.recordingStartTime) / 1000);
            const remaining = Math.max(0, 30 - elapsed);
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            document.getElementById('timerDisplay').textContent = 
                `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            if (remaining === 0) stopRecording();
        }, 1000);
    }

    function stopRecording() {
        if (window.mediaRecorder && window.mediaRecorder.state === 'recording') window.mediaRecorder.stop();
        if (window.recordingTimer) clearInterval(window.recordingTimer);
        window.recordingTimer = null;
        const timerElement = document.getElementById('recordingTimer');
        if (timerElement) timerElement.classList.add('hidden');
        if (recordVideoBtn) recordVideoBtn.classList.remove('hidden');
        if (stopVideoBtn) stopVideoBtn.classList.add('hidden');
    }

    async function startCamera() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ 
                video: { 
                    facingMode: 'environment',
                    width: { ideal: 640 },
                    height: { ideal: 480 }
                } 
            });
            window.cameraStream = stream;
            cameraVideo.srcObject = stream;
            cameraVideo.autoplay = true;
            cameraVideo.playsInline = true;
            cameraVideo.setAttribute('playsinline', '');
            cameraVideo.setAttribute('webkit-playsinline', '');
            cameraVideo.style.width = '100%';
            cameraVideo.style.height = 'auto';
            cameraVideo.style.objectFit = 'cover';
            startCameraBtn.classList.add('hidden');
            stopCameraBtn.classList.remove('hidden');
            captureBtn.classList.remove('hidden');
            Swal.fire({
                icon: 'success',
                title: 'Camera Started',
                text: 'Camera is now active. Click "Capture" to take a photo.',
                timer: 2000,
                showConfirmButton: false
            });
        } catch (err) {
            console.error('Camera error:', err);
            Swal.fire({
                icon: 'error',
                title: 'Camera Error',
                text: 'Unable to access camera. Please check permissions and try again.',
                confirmButtonColor: '#fa5246'
            });
        }
    }

    function stopCamera() {
        if (window.cameraStream) {
            window.cameraStream.getTracks().forEach(track => track.stop());
            window.cameraStream = null;
        }
        cameraVideo.srcObject = null;
        startCameraBtn.classList.remove('hidden');
        stopCameraBtn.classList.add('hidden');
        captureBtn.classList.add('hidden');
        capturedImage.classList.add('hidden');
        document.getElementById('captureTimestamp').classList.add('hidden');
    }

    function capturePhoto() {
        if (!window.cameraStream) {
            Swal.fire({
                icon: 'warning',
                title: 'Camera Not Active',
                text: 'Please start the camera first.',
                confirmButtonColor: '#faae2b'
            });
            return;
        }
        const context = captureCanvas.getContext('2d');
        captureCanvas.width = cameraVideo.videoWidth;
        captureCanvas.height = cameraVideo.videoHeight;
        context.drawImage(cameraVideo, 0, 0, captureCanvas.width, captureCanvas.height);
        const now = new Date();
        const options = {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        };
        const timestamp = now.toLocaleDateString('en-US', options);
        context.fillStyle = 'rgba(0, 0, 0, 0.7)';
        context.fillRect(10, captureCanvas.height - 60, 400, 50);
        context.fillStyle = '#ffffff';
        context.font = 'bold 16px Arial';
        context.fillText(timestamp, 20, captureCanvas.height - 30);
        capturedImage.src = captureCanvas.toDataURL('image/jpeg');
        capturedImage.classList.remove('hidden');
        const timestampElement = document.getElementById('captureTimestamp');
        const timestampText = document.getElementById('timestampText');
        timestampText.textContent = timestamp;
        timestampElement.classList.remove('hidden');
        captureCanvas.toBlob(blob => {
            const file = new File([blob], 'camera_capture_' + Date.now() + '.jpg', { 
                type: 'image/jpeg',
                lastModified: new Date().getTime()
            });
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            document.getElementById('mediaInput').files = dataTransfer.files;
            document.getElementById('mediaStatus').classList.remove('hidden');
            Swal.fire({
                icon: 'success',
                title: 'Photo Captured!',
                text: 'Photo captured on ' + timestamp,
                timer: 2000,
                showConfirmButton: false
            });
        }, 'image/jpeg', 0.8);
    }
});
