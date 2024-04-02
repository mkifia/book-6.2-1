<?php

namespace App\MessageHandler;

use App\Message\CommentMessage;
use App\Notification\CommentReviewNotification;
use App\Repository\CommentRepository;
use App\SpamChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\NotificationEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

#[AsMessageHandler]
class CommentMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface              $entityManager,
        private readonly SpamChecker                         $spamChecker,
        private readonly CommentRepository                   $commentRepository,
        private readonly MessageBusInterface                 $bus,
        private readonly WorkflowInterface                   $commentStateMachine,
        private readonly MailerInterface                     $mailer,
        #[Autowire('%admin_email%')] private readonly string $adminMailer,
    ) {
    }

    /**
     * @throws TransportExceptionInterface
     * @throws \Symfony\Component\Mailer\Exception\TransportExceptionInterface
     */
    public function __invoke(CommentMessage $message): void
    {
        $comment = $this->commentRepository->find($message->getId());
        if (!$comment) {
            return;
        }

        if ($this->commentStateMachine->can($comment, 'accept')) {
            $score = $this->spamChecker->getSpamScore($comment, $message->getContext());
            $transition = match ($score) {
                2 => 'reject_spam',
                1 => 'accept_ham',
                default => 'accept',
            };
            $this->commentStateMachine->apply($comment, $transition);
            $this->commentStateMachine->apply($comment, 'accept');
            $this->entityManager->flush();
            $this->bus->dispatch($message);
        } elseif (
            $this->commentStateMachine->can($comment, 'publish') ||
            $this->commentStateMachine->can($comment, 'publish_ham'))
        {
            $notification = new CommentReviewNotification($comment, $message->getReviewUrl());
            $this->notifier->send($notification, ...$this->notifier->getAdminRecipients());
            $this->mailer->send((new NotificationEmail())
                ->subject('New comment posted')
                ->htmlTemplate('emails/comment_notification.html.twig')
                ->from($this->adminMailer)
                ->to($this->adminMailer)
                ->context(['comment' => $comment])
            );
            $this->entityManager->flush();
        }
    }
}
