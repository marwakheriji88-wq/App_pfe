<?php

namespace App\Controller;

use App\Entity\ChatMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ChatbotController extends AbstractController
{
    private $httpClient;

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    #[Route('/chatbot', name: 'app_chatbot')]
    public function index(): Response
    {
        $user = $this->getUser();

        return $this->render('chatbot/index.html.twig', [
            'controller_name' => 'ChatbotController',
            'username' => $user->getUsername(),
        ]);
    }

    /**
     * @Route("/chatbot/response", name="chatbot_response", methods={"POST"})
     */
    public function getResponse(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $content = json_decode($request->getContent(), true);

        if (!isset($content['question']) || empty($content['question'])) {
            return new JsonResponse(['error' => 'No question provided'], 400);
        }

        $question = $content['question'];
        $userId = 1; // à remplacer dynamiquement

        // Récupère les derniers messages pour donner du contexte à Groq
        // (comme "historique" dans le notebook)
        $recentMessages = $entityManager->getRepository(ChatMessage::class)
            ->findBy(['userId' => $userId], ['createdAt' => 'DESC'], 4);
        $recentMessages = array_reverse($recentMessages);

        $history = [];
        foreach ($recentMessages as $msg) {
            $history[] = [
                'role' => $msg->getSender() === 'chatbot' ? 'assistant' : 'user',
                'content' => $msg->getMessage(),
            ];
        }

        $userMessage = new ChatMessage();
        $userMessage->setUserId($userId);
        $userMessage->setSender('user');
        $userMessage->setMessage($question);
        $userMessage->setCreatedAt(new \DateTime());

        $entityManager->persist($userMessage);

        try {
            $response = $this->httpClient->request(
                'POST',
                'http://127.0.0.1:5001/chatbot/ask',
                [
                    'json' => [
                        'question' => $question,
                        'history' => $history,
                    ],
                ]
            );

            $data = $response->toArray(false);

            if (isset($data['error'])) {
                $entityManager->flush(); // garde le message utilisateur même en cas d'erreur bot
                return new JsonResponse(['error' => $data['error']], 400);
            }

            $chatbotMessage = new ChatMessage();
            $chatbotMessage->setUserId($userId);
            $chatbotMessage->setSender('chatbot');
            $chatbotMessage->setMessage($data['response']);
            $chatbotMessage->setCreatedAt(new \DateTime());

            $entityManager->persist($chatbotMessage);
            $entityManager->flush();

            return new JsonResponse($data);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @Route("/chatbot/history", name="chatbot_history", methods={"GET"})
     */
    public function getChatHistory(EntityManagerInterface $entityManager): JsonResponse
    {
        // Fetch messages for the current user (replace with dynamic user ID)
        $userId = 1; // Replace with dynamic user ID
        $messages = $entityManager->getRepository(ChatMessage::class)
            ->findBy(['userId' => $userId], ['createdAt' => 'ASC']);

        $history = [];
        foreach ($messages as $message) {
            $history[] = [
                'sender' => $message->getSender(),
                'message' => $message->getMessage(),
                'created_at' => $message->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }

        return new JsonResponse($history);
    }

    /**
     * @Route("/chatbot/message/delete/{id}", name="chatbot_delete_message", methods={"DELETE"})
     */
    public function deleteMessage(int $id, EntityManagerInterface $entityManager): JsonResponse
    {
        $message = $entityManager->getRepository(ChatMessage::class)->find($id);

        if (!$message) {
            return new JsonResponse(['error' => 'Message not found'], 404);
        }

        try {
            $entityManager->remove($message);
            $entityManager->flush();

            return new JsonResponse(['success' => 'Message deleted']);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @Route("/chatbot/conversation/delete/{userId}", name="chatbot_delete_conversation", methods={"DELETE"})
     */
    public function deleteConversation(int $userId, EntityManagerInterface $entityManager): JsonResponse
    {
        $messages = $entityManager->getRepository(ChatMessage::class)->findBy(['userId' => $userId]);

        if (!$messages) {
            return new JsonResponse(['error' => 'No conversation found'], 404);
        }

        try {
            foreach ($messages as $message) {
                $entityManager->remove($message);
            }

            $entityManager->flush();

            return new JsonResponse(['success' => 'Conversation deleted']);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}