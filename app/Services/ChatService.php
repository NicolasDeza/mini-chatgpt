<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\CustomInstruction; // Ajout de cet import

class ChatService
{
    private $baseUrl;
    private $apiKey;
    private $client;
    public const DEFAULT_MODEL = 'meta-llama/llama-3.2-11b-vision-instruct:free';

    public function __construct()
    {
        $this->baseUrl = config('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        $this->apiKey = config('services.openrouter.api_key');
        $this->client = $this->createOpenAIClient();
    }

    /**
     * @return array<array-key, array{
     *     id: string,
     *     name: string,
     *     context_length: int,
     *     max_completion_tokens: int,
     *     pricing: array{prompt: int, completion: int}
     * }>
     */
    public function getModels(): array
    {
        return cache()->remember('openai.models', now()->addHour(), function () {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->get($this->baseUrl . '/models');

            return collect($response->json()['data'])
                ->filter(function ($model) {
                    return str_ends_with($model['id'], ':free');
                })
                ->sortBy('name')
                ->map(function ($model) {
                    return [
                        'id' => $model['id'],
                        'name' => $model['name'],
                        'context_length' => $model['context_length'],
                        'max_completion_tokens' => $model['top_provider']['max_completion_tokens'],
                        'pricing' => $model['pricing'],
                    ];
                })
                ->values()
                ->all()
            ;
        });
    }

    /**
     * @param array{role: 'user'|'assistant'|'system'|'function', content: string} $messages
     * @param string|null $model
     * @param float $temperature
     *
     * @return string
     */
    public function sendMessage(array $messages, string $model = null, float $temperature = 0.7): string
    {
        try {
            // Log des messages avant traitement
            logger()->info('Messages reçus:', [
                'messages_count' => count($messages),
                'messages' => $messages
            ]);

            logger()->info('Envoi du message', [
                'model' => $model,
                'temperature' => $temperature,
            ]);

            $models = collect($this->getModels());
            if (!$model || !$models->contains('id', $model)) {
                $model = self::DEFAULT_MODEL;
                logger()->info('Modèle par défaut utilisé:', ['model' => $model]);
            }

            // Log du système prompt
            $systemPrompt = $this->getChatSystemPrompt();
            logger()->info('System Prompt:', ['prompt' => $systemPrompt]);

            // Log de tous les messages combinés
            $allMessages = [$systemPrompt, ...$messages];
            logger()->info('Messages combinés:', [
                'total_messages' => count($allMessages),
                'all_messages' => $allMessages
            ]);

            $response = $this->client->chat()->create([
                'model' => $model,
                'messages' => $allMessages,
                'temperature' => $temperature,
            ]);

            // Log de la réponse complète
            logger()->info('Réponse API complète:', ['response' => $response]);

            // Vérification plus détaillée de la structure de réponse
            if (!is_object($response)) {
                throw new \Exception("La réponse de l'API n'est pas un objet valide");
            }

            if (!isset($response->choices) || !is_array($response->choices) || empty($response->choices)) {
                throw new \Exception("La réponse de l'API ne contient pas de choix valides");
            }

            if (!isset($response->choices[0]->message->content)) {
                throw new \Exception("Le format de la réponse de l'API est invalide");
            }

            $content = $response->choices[0]->message->content;
            logger()->info('Contenu de la réponse:', ['content' => $content]);

            return $content;
        } catch (\Exception $e) {
            // Log détaillé de l'erreur
            logger()->error('Erreur détaillée dans sendMessage:', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_trace' => $e->getTraceAsString(),
                'last_messages' => $messages ?? [],
                'model' => $model,
                'temperature' => $temperature
            ]);

            if ($e->getMessage() === 'Undefined array key "choices"') {
                throw new \Exception("Limite de messages atteinte");
            }

            logger()->error('Erreur dans sendMessage:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    private function createOpenAIClient(): \OpenAI\Client
    {
        return \OpenAI::factory()
            ->withApiKey($this->apiKey)
            ->withBaseUri($this->baseUrl)
            ->make()
        ;
    }

    /**
     * @return array{role: 'system', content: string}
     */
    private function getChatSystemPrompt(): array
    {
        $user = auth()->user();
        $now = now()->locale('fr')->format('l d F Y H:i');

        // Log des informations de l'utilisateur
        logger()->info('Information utilisateur:', [
            'user_id' => $user->id,
            'user_name' => $user->name
        ]);

        // Récupérer et logger les instructions personnalisées
        $customInstruction = CustomInstruction::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        logger()->info('Instructions personnalisées:', [
            'has_instructions' => !is_null($customInstruction),
            'instruction_data' => $customInstruction
        ]);

        // Récupérer les instructions personnalisées actives
        $customInstruction = CustomInstruction::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        $systemPrompt = "Tu es un assistant de chat. La date et l'heure actuelle est le {$now}.\n";
        $systemPrompt .= "Tu es actuellement utilisé par {$user->name}.\n";

        if ($customInstruction) {
            if ($customInstruction->about_user) {
                $systemPrompt .= "\nÀ propos de l'utilisateur:\n" . $customInstruction->about_user;
            }
            if ($customInstruction->preference) {
                $systemPrompt .= "\nPréférences de réponse:\n" . $customInstruction->preference;
            }
        }

        return [
            'role' => 'system',
            'content' => $systemPrompt,
        ];
    }
}
