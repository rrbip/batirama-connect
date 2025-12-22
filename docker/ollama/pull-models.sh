#!/bin/bash

# ===========================================
# AI-Manager CMS - Ollama Model Puller
# ===========================================
# Télécharge les modèles IA configurés

MODELS="${1:-nomic-embed-text,mistral:7b}"

echo "📥 Téléchargement des modèles Ollama..."

IFS=',' read -ra MODEL_LIST <<< "$MODELS"

for model in "${MODEL_LIST[@]}"; do
    model=$(echo "$model" | xargs)
    echo "   ⏳ $model..."
    ollama pull "$model" 2>&1 | tail -1
done

echo "✅ Tous les modèles sont téléchargés"
ollama list
