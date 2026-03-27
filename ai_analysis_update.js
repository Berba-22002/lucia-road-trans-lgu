// Updated analyzeImageWithAI function with hazard level
async function analyzeImageWithAI(imageElement, resultsContainerId) {
    if (!model) {
        console.log('Beripikado AI not initialized');
        return;
    }
    
    try {
        const resultsContainer = document.getElementById(resultsContainerId);
        const parentContainer = resultsContainer.parentElement;
        
        resultsContainer.innerHTML = `
            <div class="flex items-center justify-center py-4">
                <i class="fas fa-spinner fa-spin text-blue-600 mr-2"></i>
                <span>Beripikado AI is analyzing the image...</span>
            </div>
        `;
        parentContainer.classList.remove('hidden');
        
        const predictions = await model.predict(imageElement);
        predictions.sort((a, b) => b.probability - a.probability);
        
        const topPrediction = predictions[0];
        const percentage = (topPrediction.probability * 100).toFixed(1);
        
        // Calculate hazard level
        let hazardLevel = 'base';
        if (topPrediction.probability > 0.8) hazardLevel = 'high';
        else if (topPrediction.probability > 0.6) hazardLevel = 'medium';
        else if (topPrediction.probability > 0.4) hazardLevel = 'low';
        
        const aiAnalysisData = {
            predictions: predictions.map(p => ({
                className: p.className,
                probability: p.probability
            })),
            topPrediction: predictions[0],
            hazardLevel: hazardLevel
        };
        
        let aiInput = document.getElementById('ai_analysis_input');
        if (!aiInput) {
            aiInput = document.createElement('input');
            aiInput.type = 'hidden';
            aiInput.name = 'ai_analysis';
            aiInput.id = 'ai_analysis_input';
            document.getElementById('hazardForm').appendChild(aiInput);
        }
        aiInput.value = JSON.stringify(aiAnalysisData);
        
        // Color mapping for hazard levels
        const levelColors = {
            high: { bg: 'bg-red-100', text: 'text-red-700', icon: 'fa-exclamation-circle', border: 'border-red-300' },
            medium: { bg: 'bg-yellow-100', text: 'text-yellow-700', icon: 'fa-exclamation-triangle', border: 'border-yellow-300' },
            low: { bg: 'bg-blue-100', text: 'text-blue-700', icon: 'fa-info-circle', border: 'border-blue-300' },
            base: { bg: 'bg-gray-100', text: 'text-gray-700', icon: 'fa-circle', border: 'border-gray-300' }
        };
        
        const colors = levelColors[hazardLevel];
        
        let resultsHTML = `
            <div class="${colors.bg} p-3 rounded-lg border-l-4 border-blue-500">
                <div class="flex justify-between items-center">
                    <span class="font-semibold ${colors.text}">${topPrediction.className}</span>
                    <span class="text-sm ${colors.text}">${percentage}%</span>
                </div>
                <div class="text-xs text-blue-600 mt-1">Primary Detection</div>
            </div>
            <div class="mt-3 p-3 ${colors.bg} rounded-lg border ${colors.border}">
                <div class="flex items-center">
                    <i class="fas ${colors.icon} ${colors.text} mr-2"></i>
                    <span class="font-bold ${colors.text}">Hazard Level: ${hazardLevel.toUpperCase()}</span>
                </div>
            </div>
        `;
        
        resultsContainer.innerHTML = resultsHTML;
        
    } catch (error) {
        console.error('Error analyzing image:', error);
        const resultsContainer = document.getElementById(resultsContainerId);
        resultsContainer.innerHTML = `
            <div class="text-red-600 text-center py-4">
                <i class="fas fa-exclamation-circle mr-2"></i>
                Failed to analyze image. Please try again.
            </div>
        `;
    }
}
