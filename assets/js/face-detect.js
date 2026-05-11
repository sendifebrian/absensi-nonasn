// assets/js/face-detect.js
async function detectFace(imageDataUrl) {
    try {
        await faceapi.nets.tinyFaceDetector.loadFromUri('https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/weights');
        
        const img = new Image();
        img.src = imageDataUrl;
        await new Promise(resolve => img.onload = resolve);

        const detection = await faceapi.detectSingleFace(
            img, 
            new faceapi.TinyFaceDetectorOptions()
        ).withFaceLandmarks();

        return detection !== undefined;
    } catch (err) {
        console.error("Face detection error:", err);
        return false;
    }
}