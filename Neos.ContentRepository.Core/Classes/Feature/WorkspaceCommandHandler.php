<?php

/*
 * This file is part of the Neos.ContentRepository package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

declare(strict_types=1);

namespace Neos\ContentRepository\Core\Feature;

use Neos\ContentRepository\Core\CommandHandler\CommandHandlerInterface;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\CommandHandler\CommandResult;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\EventStore\DecoratedEvent;
use Neos\ContentRepository\Core\EventStore\EventNormalizer;
use Neos\ContentRepository\Core\EventStore\EventPersister;
use Neos\ContentRepository\Core\EventStore\Events;
use Neos\ContentRepository\Core\EventStore\EventsToPublish;
use Neos\ContentRepository\Core\Feature\Common\NodeIdentifiersToPublishOrDiscard;
use Neos\ContentRepository\Core\Feature\WorkspaceDiscarding\Command\DiscardIndividualNodesFromWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceDiscarding\Command\DiscardWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\PublishIndividualNodesFromWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\WorkspaceRebaseStatistics;
use Neos\ContentRepository\Core\Feature\ContentStreamCreation\Command\CreateContentStream;
use Neos\ContentRepository\Core\Feature\ContentStreamForking\Command\ForkContentStream;
use Neos\ContentRepository\Core\Feature\ContentStreamForking\Event\ContentStreamWasForked;
use Neos\ContentRepository\Core\SharedModel\Exception\ContentStreamAlreadyExists;
use Neos\ContentRepository\Core\SharedModel\Exception\ContentStreamDoesNotExistYet;
use Neos\ContentRepository\Core\Feature\Common\RebasableToOtherContentStreamsInterface;
use Neos\ContentRepository\Core\Feature\Common\PublishableToOtherContentStreamsInterface;
use Neos\ContentRepository\Core\Feature\Common\MatchableWithNodeIdentifierToPublishOrDiscardInterface;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Command\CreateRootWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Command\CreateWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\PublishWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Command\RebaseWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceDiscarding\Event\WorkspaceWasDiscarded;
use Neos\ContentRepository\Core\Feature\WorkspaceDiscarding\Event\WorkspaceWasPartiallyDiscarded;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasPartiallyPublished;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasPublished;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Event\RootWorkspaceWasCreated;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Event\WorkspaceRebaseFailed;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Event\WorkspaceWasCreated;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Event\WorkspaceWasRebased;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Exception\BaseWorkspaceDoesNotExist;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Exception\BaseWorkspaceHasBeenModifiedInTheMeantime;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Exception\WorkspaceAlreadyExists;
use Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceDoesNotExist;
use Neos\ContentRepository\Core\SharedModel\Exception\WorkspaceHasNoBaseWorkspaceName;
use Neos\ContentRepository\Core\Projection\Workspace\Workspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\ContentStreamIdentifier;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\EventStore\EventStoreInterface;
use Neos\EventStore\Exception\ConcurrencyException;
use Neos\EventStore\Model\Event\StreamName;
use Neos\EventStore\Model\EventEnvelope;
use Neos\EventStore\Model\EventStream\ExpectedVersion;

/**
 * @internal from userland, you'll use ContentRepository::handle to dispatch commands
 */
final class WorkspaceCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly EventPersister $eventPersister,
        private readonly EventStoreInterface $eventStore,
        private readonly EventNormalizer $eventNormalizer,
    ) {
    }

    public function canHandle(CommandInterface $command): bool
    {
        return $command instanceof CreateWorkspace
            || $command instanceof CreateRootWorkspace
            || $command instanceof PublishWorkspace
            || $command instanceof RebaseWorkspace
            || $command instanceof PublishIndividualNodesFromWorkspace
            || $command instanceof DiscardIndividualNodesFromWorkspace
            || $command instanceof DiscardWorkspace;
    }

    public function handle(CommandInterface $command, ContentRepository $contentRepository): EventsToPublish
    {
        if ($command instanceof CreateWorkspace) {
            return $this->handleCreateWorkspace($command, $contentRepository);
        } elseif ($command instanceof CreateRootWorkspace) {
            return $this->handleCreateRootWorkspace($command, $contentRepository);
        } elseif ($command instanceof PublishWorkspace) {
            return $this->handlePublishWorkspace($command, $contentRepository);
        } elseif ($command instanceof RebaseWorkspace) {
            return $this->handleRebaseWorkspace($command, $contentRepository);
        } elseif ($command instanceof PublishIndividualNodesFromWorkspace) {
            return $this->handlePublishIndividualNodesFromWorkspace($command, $contentRepository);
        } elseif ($command instanceof DiscardIndividualNodesFromWorkspace) {
            return $this->handleDiscardIndividualNodesFromWorkspace($command, $contentRepository);
        } elseif ($command instanceof DiscardWorkspace) {
            return $this->handleDiscardWorkspace($command, $contentRepository);
        }

        throw new \RuntimeException('invalid command');
    }

    /**
     * @throws BaseWorkspaceDoesNotExist
     * @throws ContentStreamAlreadyExists
     * @throws ContentStreamDoesNotExistYet
     * @throws WorkspaceAlreadyExists
     */
    private function handleCreateWorkspace(
        CreateWorkspace $command,
        ContentRepository $contentRepository
    ): EventsToPublish {
        $existingWorkspace = $contentRepository->getWorkspaceFinder()->findOneByName($command->workspaceName);
        if ($existingWorkspace !== null) {
            throw new WorkspaceAlreadyExists(sprintf(
                'The workspace %s already exists',
                $command->workspaceName
            ), 1505830958921);
        }

        $baseWorkspace = $contentRepository->getWorkspaceFinder()->findOneByName($command->baseWorkspaceName);
        if ($baseWorkspace === null) {
            throw new BaseWorkspaceDoesNotExist(sprintf(
                'The workspace %s (base workspace of %s) does not exist',
                $command->baseWorkspaceName,
                $command->workspaceName
            ), 1513890708);
        }

        // When the workspace is created, we first have to fork the content stream
        $contentRepository->handle(
            new ForkContentStream(
                $command->newContentStreamIdentifier,
                $baseWorkspace->currentContentStreamIdentifier,
                $command->initiatingUserIdentifier
            )
        )->block();

        $events = Events::with(
            new WorkspaceWasCreated(
                $command->workspaceName,
                $command->baseWorkspaceName,
                $command->workspaceTitle,
                $command->workspaceDescription,
                $command->initiatingUserIdentifier,
                $command->newContentStreamIdentifier,
                $command->workspaceOwner
            ),
        );

        return new EventsToPublish(
            StreamName::fromString('Neos.ContentRepository:Workspace:' . $command->workspaceName),
            $events,
            ExpectedVersion::ANY()
        );
    }

    /**
     * @param CreateRootWorkspace $command
     * @return EventsToPublish
     * @throws WorkspaceAlreadyExists
     * @throws ContentStreamAlreadyExists
     */
    public function handleCreateRootWorkspace(
        CreateRootWorkspace $command,
        ContentRepository $contentRepository
    ): EventsToPublish {
        $existingWorkspace = $contentRepository->getWorkspaceFinder()->findOneByName($command->workspaceName);
        if ($existingWorkspace !== null) {
            throw new WorkspaceAlreadyExists(sprintf(
                'The workspace %s already exists',
                $command->workspaceName
            ), 1505848624450);
        }

        $contentStreamIdentifier = $command->newContentStreamIdentifier;
        $contentRepository->handle(
            new CreateContentStream(
                $contentStreamIdentifier,
                $command->initiatingUserIdentifier
            )
        )->block();

        $events = Events::with(
            new RootWorkspaceWasCreated(
                $command->workspaceName,
                $command->workspaceTitle,
                $command->workspaceDescription,
                $command->initiatingUserIdentifier,
                $contentStreamIdentifier
            )
        );

        return new EventsToPublish(
            StreamName::fromString('Neos.ContentRepository:Workspace:' . $command->workspaceName),
            $events,
            ExpectedVersion::ANY()
        );
    }

    /**
     * @throws BaseWorkspaceDoesNotExist
     * @throws BaseWorkspaceHasBeenModifiedInTheMeantime
     * @throws ContentStreamAlreadyExists
     * @throws ContentStreamDoesNotExistYet
     * @throws WorkspaceDoesNotExist
     * @throws \Symfony\Component\Serializer\Exception\ExceptionInterface
     * @throws WorkspaceHasNoBaseWorkspaceName
     */
    public function handlePublishWorkspace(
        PublishWorkspace $command,
        ContentRepository $contentRepository
    ): EventsToPublish {
        $workspace = $this->requireWorkspace($command->workspaceName, $contentRepository);
        $baseWorkspace = $this->requireBaseWorkspace($workspace, $contentRepository);

        $this->publishContentStream(
            $workspace->currentContentStreamIdentifier,
            $baseWorkspace->currentContentStreamIdentifier
        )?->block();

        // After publishing a workspace, we need to again fork from Base.
        $newContentStream = ContentStreamIdentifier::create();
        $contentRepository->handle(
            new ForkContentStream(
                $newContentStream,
                $baseWorkspace->currentContentStreamIdentifier,
                $command->initiatingUserIdentifier
            )
        )->block();

        $streamName = StreamName::fromString('Neos.ContentRepository:Workspace:' . $command->workspaceName);
        $events = Events::with(
            new WorkspaceWasPublished(
                $command->workspaceName,
                $baseWorkspace->workspaceName,
                $newContentStream,
                $workspace->currentContentStreamIdentifier,
                $command->initiatingUserIdentifier
            )
        );
        // if we got so far without an Exception, we can switch the Workspace's active Content stream.
        return new EventsToPublish(
            $streamName,
            $events,
            ExpectedVersion::ANY()
        );
    }

    /**
     * @throws BaseWorkspaceHasBeenModifiedInTheMeantime
     * @throws \Exception
     */
    private function publishContentStream(
        ContentStreamIdentifier $contentStreamIdentifier,
        ContentStreamIdentifier $baseContentStreamIdentifier
    ): ?CommandResult {
        $contentStreamName = ContentStreamEventStreamName::fromContentStreamIdentifier($contentStreamIdentifier);
        $baseWorkspaceContentStreamName = ContentStreamEventStreamName::fromContentStreamIdentifier(
            $baseContentStreamIdentifier
        );

        // TODO: please check the code below in-depth. it does:
        // - copy all events from the "user" content stream which implement "PublishableToOtherContentStreamsInterface"
        // - extract the initial ContentStreamWasForked event,
        //   to read the version of the source content stream when the fork occurred
        // - ensure that no other changes have been done in the meantime in the base content stream


        $streamName = $contentStreamName->getEventStreamName();

        /** @var array<int,EventEnvelope> $workspaceContentStream */
        $workspaceContentStream = iterator_to_array($this->eventStore->load($streamName));

        $events = [];
        $contentStreamWasForkedEvent = null;
        foreach ($workspaceContentStream as $eventEnvelope) {
            assert($eventEnvelope instanceof EventEnvelope);
            $event = $this->eventNormalizer->denormalize($eventEnvelope->event);

            if ($event instanceof ContentStreamWasForked) {
                if ($contentStreamWasForkedEvent !== null) {
                    throw new \RuntimeException(
                        'Invariant violation: The content stream "' . $contentStreamIdentifier
                        . '" has two forked events.',
                        1658740373
                    );
                }
                $contentStreamWasForkedEvent = $event;
            } elseif ($event instanceof PublishableToOtherContentStreamsInterface) {
                /** @var EventInterface $copiedEvent */
                $copiedEvent = $event->createCopyForContentStream($baseContentStreamIdentifier);
                // We need to add the event metadata here for rebasing in nested workspace situations
                // (and for exporting)
                $events[] = DecoratedEvent::withMetadata(
                    $copiedEvent,
                    $eventEnvelope->event->metadata
                );
            }
        }

        if ($contentStreamWasForkedEvent === null) {
            throw new \RuntimeException('Invariant violation: The content stream "' . $contentStreamIdentifier
                . '" has NO forked event.', 1658740407);
        }

        if (count($events) === 0) {
            return null;
        }
        try {
            return $this->eventPersister->publishEvents(
                new EventsToPublish(
                    $baseWorkspaceContentStreamName->getEventStreamName(),
                    Events::fromArray($events),
                    ExpectedVersion::fromVersion($contentStreamWasForkedEvent->versionOfSourceContentStream)
                )
            );
        } catch (ConcurrencyException $e) {
            throw new BaseWorkspaceHasBeenModifiedInTheMeantime(sprintf(
                'The base workspace has been modified in the meantime; please rebase.'
                    . ' Expected version %d of source content stream %s',
                $contentStreamWasForkedEvent->versionOfSourceContentStream->value,
                $baseContentStreamIdentifier
            ));
        }
    }

    /**
     * @throws BaseWorkspaceDoesNotExist
     * @throws WorkspaceDoesNotExist
     * @throws \Exception
     * @throws \Neos\Flow\Property\Exception
     * @throws \Neos\Flow\Security\Exception
     */
    public function handleRebaseWorkspace(
        RebaseWorkspace $command,
        ContentRepository $contentRepository
    ): EventsToPublish {
        $workspace = $this->requireWorkspace($command->workspaceName, $contentRepository);
        $baseWorkspace = $this->requireBaseWorkspace($workspace, $contentRepository);

        // TODO: please check the code below in-depth. it does:
        // - fork a new content stream
        // - extract the commands from the to-be-rebased content stream; and applies them on the new content stream
        $rebasedContentStream = $command->rebasedContentStreamIdentifier;
        $contentRepository->handle(
            new ForkContentStream(
                $rebasedContentStream,
                $baseWorkspace->currentContentStreamIdentifier,
                $command->initiatingUserIdentifier
            )
        )->block();

        $workspaceContentStreamName = ContentStreamEventStreamName::fromContentStreamIdentifier(
            $workspace->currentContentStreamIdentifier
        );

        $originalCommands = $this->extractCommandsFromContentStreamMetadata($workspaceContentStreamName);
        $rebaseStatistics = new WorkspaceRebaseStatistics();
        foreach ($originalCommands as $i => $originalCommand) {
            if (!($originalCommand instanceof RebasableToOtherContentStreamsInterface)) {
                throw new \RuntimeException(
                    'ERROR: The command ' . get_class($originalCommand)
                        . ' does not implement RebasableToOtherContentStreamsInterface; but it should!'
                );
            }

            // try to apply the command on the rebased content stream
            $commandToRebase = $originalCommand->createCopyForContentStream($rebasedContentStream);
            try {
                $contentRepository->handle($commandToRebase)->block();
                // if we came this far, we know the command was applied successfully.
                $rebaseStatistics->commandRebaseSuccess();
            } catch (\Exception $e) {
                $fullCommandListSoFar = '';
                for ($a = 0; $a <= $i; $a++) {
                    $fullCommandListSoFar .= "\n - " . get_class($originalCommands[$a]);

                    if ($originalCommands[$a] instanceof \JsonSerializable) {
                        $fullCommandListSoFar .= ' ' . json_encode($originalCommands[$a]);
                    }
                }

                $rebaseStatistics->commandRebaseError(sprintf(
                    "The content stream %s cannot be rebased. Error with command %d (%s)"
                        . " - see nested exception for details.\n\n The base workspace %s is at content stream %s."
                        . "\n The full list of commands applied so far is: %s",
                    $workspaceContentStreamName,
                    $i,
                    get_class($commandToRebase),
                    $baseWorkspace->workspaceName,
                    $baseWorkspace->currentContentStreamIdentifier,
                    $fullCommandListSoFar
                ), $e);
            }
        }

        $streamName = StreamName::fromString('Neos.ContentRepository:Workspace:' . $command->workspaceName);

        // if we got so far without an Exception, we can switch the Workspace's active Content stream.
        if (!$rebaseStatistics->hasErrors()) {
            $events = Events::with(
                new WorkspaceWasRebased(
                    $command->workspaceName,
                    $rebasedContentStream,
                    $workspace->currentContentStreamIdentifier,
                    $command->initiatingUserIdentifier
                ),
            );

            return new EventsToPublish(
                $streamName,
                $events,
                ExpectedVersion::ANY()
            );
        } else {
            // an error occurred during the rebase; so we need to record this using a "WorkspaceRebaseFailed" event.

            $event = Events::with(
                new WorkspaceRebaseFailed(
                    $command->workspaceName,
                    $rebasedContentStream,
                    $workspace->currentContentStreamIdentifier,
                    $command->initiatingUserIdentifier,
                    $rebaseStatistics->getErrors()
                )
            );

            return new EventsToPublish(
                $streamName,
                $event,
                ExpectedVersion::ANY()
            );
        }
    }

    /**
     * @return array<int,object>
     */
    private function extractCommandsFromContentStreamMetadata(
        ContentStreamEventStreamName $workspaceContentStreamName
    ): array {
        $workspaceContentStream = $this->eventStore->load($workspaceContentStreamName->getEventStreamName());

        $commands = [];
        foreach ($workspaceContentStream as $eventEnvelope) {
            $metadata = $eventEnvelope->event->metadata->value;
            // TODO: Add this logic to the NodeAggregateCommandHandler;
            // so that we can be sure these can be parsed again.
            if (isset($metadata['commandClass'])) {
                $commandToRebaseClass = $metadata['commandClass'];
                $commandToRebasePayload = $metadata['commandPayload'];
                if (!method_exists($commandToRebaseClass, 'fromArray')) {
                    throw new \RuntimeException(sprintf(
                        'Command "%s" can\'t be rebased because it does not implement a static "fromArray" constructor',
                        $commandToRebaseClass
                    ), 1547815341);
                }
                $commands[] = $commandToRebaseClass::fromArray($commandToRebasePayload);
            }
        }

        return $commands;
    }

    /**
     * This method is like a combined Rebase and Publish!
     *
     * @throws BaseWorkspaceDoesNotExist
     * @throws BaseWorkspaceHasBeenModifiedInTheMeantime
     * @throws ContentStreamAlreadyExists
     * @throws ContentStreamDoesNotExistYet
     * @throws WorkspaceDoesNotExist
     * @throws \Exception
     */
    public function handlePublishIndividualNodesFromWorkspace(
        PublishIndividualNodesFromWorkspace $command,
        ContentRepository $contentRepository
    ): EventsToPublish {
        $workspace = $this->requireWorkspace($command->workspaceName, $contentRepository);
        $baseWorkspace = $this->requireBaseWorkspace($workspace, $contentRepository);

        // 1) separate commands in two halves - the ones MATCHING the nodes from the command, and the REST
        $workspaceContentStreamName = ContentStreamEventStreamName::fromContentStreamIdentifier(
            $workspace->currentContentStreamIdentifier
        );

        $originalCommands = $this->extractCommandsFromContentStreamMetadata($workspaceContentStreamName);
        /** @var RebasableToOtherContentStreamsInterface[] $matchingCommands */
        $matchingCommands = [];
        /** @var RebasableToOtherContentStreamsInterface[] $remainingCommands */
        $remainingCommands = [];

        foreach ($originalCommands as $originalCommand) {
            if (!$originalCommand instanceof MatchableWithNodeIdentifierToPublishOrDiscardInterface) {
                throw new \Exception(
                    'Command class ' . get_class($originalCommand) . ' does not implement '
                        . MatchableWithNodeIdentifierToPublishOrDiscardInterface::class,
                    1645393655
                );
            }
            if ($this->commandMatchesAtLeastOneNode($originalCommand, $command->nodesToPublish)) {
                $matchingCommands[] = $originalCommand;
            } else {
                $remainingCommands[] = $originalCommand;
            }
        }

        // 2) fork a new contentStream, based on the base WS, and apply MATCHING
        $matchingContentStream = $command->contentStreamIdentifierForMatchingPart;
        $contentRepository->handle(
            new ForkContentStream(
                $matchingContentStream,
                $baseWorkspace->currentContentStreamIdentifier,
                $command->initiatingUserIdentifier
            )
        )->block();

        foreach ($matchingCommands as $matchingCommand) {
            if (!($matchingCommand instanceof RebasableToOtherContentStreamsInterface)) {
                throw new \RuntimeException(
                    'ERROR: The command ' . get_class($matchingCommand)
                        . ' does not implement RebasableToOtherContentStreamsInterface; but it should!'
                );
            }

            $contentRepository->handle($matchingCommand->createCopyForContentStream($matchingContentStream))->block();
        }

        // 3) fork a new contentStream, based on the matching content stream, and apply REST
        $remainingContentStream = $command->contentStreamIdentifierForRemainingPart;
        $contentRepository->handle(
            new ForkContentStream(
                $remainingContentStream,
                $matchingContentStream,
                $command->initiatingUserIdentifier
            )
        )->block();

        foreach ($remainingCommands as $remainingCommand) {
            if (!$remainingCommand instanceof RebasableToOtherContentStreamsInterface) {
                throw new \Exception(
                    'Command class ' . get_class($remainingCommand) . ' does not implement '
                        . RebasableToOtherContentStreamsInterface::class,
                    1645393626
                );
            }
            $contentRepository->handle($remainingCommand->createCopyForContentStream($remainingContentStream))
                ->block();
        }

        // 4) if that all worked out, take EVENTS(MATCHING) and apply them to base WS.
        $this->publishContentStream(
            $matchingContentStream,
            $baseWorkspace->currentContentStreamIdentifier
        )?->block();

        // 5) TODO Re-target base workspace

        // 6) switch content stream to forked WS.
        // if we got so far without an Exception, we can switch the Workspace's active Content stream.
        $streamName = StreamName::fromString('Neos.ContentRepository:Workspace:' . $command->workspaceName);
        $events = Events::with(
            new WorkspaceWasPartiallyPublished(
                $command->workspaceName,
                $baseWorkspace->workspaceName,
                $remainingContentStream,
                $workspace->currentContentStreamIdentifier,
                $command->nodesToPublish,
                $command->initiatingUserIdentifier
            ),
        );

        // It is safe to only return the last command result,
        // as the commands which were rebased are already executed "synchronously"
        return new EventsToPublish(
            $streamName,
            $events,
            ExpectedVersion::ANY()
        );
    }

    /**
     * This method is like a Rebase while dropping some modifications!
     *
     * @throws BaseWorkspaceDoesNotExist
     * @throws WorkspaceDoesNotExist
     * @throws WorkspaceHasNoBaseWorkspaceName
     * @throws \Neos\ContentRepository\Core\SharedModel\Exception\NodeConstraintException
     * @throws \Neos\ContentRepository\Core\SharedModel\Exception\NodeTypeNotFoundException
     * @throws \Symfony\Component\Serializer\Exception\ExceptionInterface
     */
    public function handleDiscardIndividualNodesFromWorkspace(
        DiscardIndividualNodesFromWorkspace $command,
        ContentRepository $contentRepository
    ): EventsToPublish {
        $workspace = $this->requireWorkspace($command->workspaceName, $contentRepository);
        $baseWorkspace = $this->requireBaseWorkspace($workspace, $contentRepository);

        // 1) filter commands, only keeping the ones NOT MATCHING the nodes from the command
        // (i.e. the modifications we want to keep)
        $workspaceContentStreamName = ContentStreamEventStreamName::fromContentStreamIdentifier(
            $workspace->currentContentStreamIdentifier
        );

        $originalCommands = $this->extractCommandsFromContentStreamMetadata($workspaceContentStreamName);
        $commandsToKeep = [];

        foreach ($originalCommands as $originalCommand) {
            if (!$originalCommand instanceof MatchableWithNodeIdentifierToPublishOrDiscardInterface) {
                throw new \Exception(
                    'Command class ' . get_class($originalCommand) . ' does not implement '
                    . MatchableWithNodeIdentifierToPublishOrDiscardInterface::class,
                    1645393476
                );
            }
            if (!$this->commandMatchesAtLeastOneNode($originalCommand, $command->nodesToDiscard)) {
                $commandsToKeep[] = $originalCommand;
            }
        }

        // 2) fork a new contentStream, based on the base WS, and apply the commands to keep
        $newContentStream = $command->newContentStreamIdentifier;
        $contentRepository->handle(
            new ForkContentStream(
                $newContentStream,
                $baseWorkspace->currentContentStreamIdentifier,
                $command->initiatingUserIdentifier
            )
        )->block();

        foreach ($commandsToKeep as $commandToKeep) {
            if (!$commandToKeep instanceof RebasableToOtherContentStreamsInterface) {
                throw new \Exception(
                    'Command class ' . get_class($commandToKeep) . ' does not implement '
                        . RebasableToOtherContentStreamsInterface::class,
                    1645393476
                );
            }
            $contentRepository->handle($commandToKeep->createCopyForContentStream($newContentStream))
                ->block();
        }

        // 3) switch content stream to forked WS.
        // if we got so far without an Exception, we can switch the Workspace's active Content stream.
        $streamName = StreamName::fromString('Neos.ContentRepository:Workspace:' . $command->workspaceName);
        $events = Events::with(
            new WorkspaceWasPartiallyDiscarded(
                $command->workspaceName,
                $newContentStream,
                $workspace->currentContentStreamIdentifier,
                $command->nodesToDiscard,
                $command->initiatingUserIdentifier
            )
        );

        // It is safe to only return the last command result,
        // as the commands which were rebased are already executed "synchronously"
        return new EventsToPublish(
            $streamName,
            $events,
            ExpectedVersion::ANY()
        );
    }

    private function commandMatchesAtLeastOneNode(
        MatchableWithNodeIdentifierToPublishOrDiscardInterface $command,
        NodeIdentifiersToPublishOrDiscard $nodeIdentifiers
    ): bool {
        foreach ($nodeIdentifiers as $nodeIdentifier) {
            if ($command->matchesNodeIdentifier($nodeIdentifier)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws BaseWorkspaceDoesNotExist
     * @throws WorkspaceDoesNotExist
     * @throws WorkspaceHasNoBaseWorkspaceName
     */
    public function handleDiscardWorkspace(
        DiscardWorkspace $command,
        ContentRepository $contentRepository
    ): EventsToPublish {
        $workspace = $this->requireWorkspace($command->workspaceName, $contentRepository);
        $baseWorkspace = $this->requireBaseWorkspace($workspace, $contentRepository);

        $newContentStream = $command->newContentStreamIdentifier;
        $contentRepository->handle(
            new ForkContentStream(
                $newContentStream,
                $baseWorkspace->currentContentStreamIdentifier,
                $command->initiatingUserIdentifier
            )
        )->block();

        // if we got so far without an Exception, we can switch the Workspace's active Content stream.
        $streamName = StreamName::fromString('Neos.ContentRepository:Workspace:' . $command->workspaceName);
        $events = Events::with(
            new WorkspaceWasDiscarded(
                $command->workspaceName,
                $newContentStream,
                $workspace->currentContentStreamIdentifier,
                $command->initiatingUserIdentifier
            )
        );

        // It is safe to only return the last command result,
        // as the commands which were rebased are already executed "synchronously"
        return new EventsToPublish(
            $streamName,
            $events,
            ExpectedVersion::ANY()
        );
    }

    /**
     * @throws WorkspaceDoesNotExist
     */
    private function requireWorkspace(WorkspaceName $workspaceName, ContentRepository $contentRepository): Workspace
    {
        $workspace = $contentRepository->getWorkspaceFinder()->findOneByName($workspaceName);
        if (is_null($workspace)) {
            throw WorkspaceDoesNotExist::butWasSupposedTo($workspaceName);
        }

        return $workspace;
    }

    /**
     * @throws WorkspaceHasNoBaseWorkspaceName
     * @throws BaseWorkspaceDoesNotExist
     */
    private function requireBaseWorkspace(Workspace $workspace, ContentRepository $contentRepository): Workspace
    {
        if (is_null($workspace->baseWorkspaceName)) {
            throw WorkspaceHasNoBaseWorkspaceName::butWasSupposedTo($workspace->workspaceName);
        }

        $baseWorkspace = $contentRepository->getWorkspaceFinder()->findOneByName($workspace->baseWorkspaceName);
        if ($baseWorkspace === null) {
            throw BaseWorkspaceDoesNotExist::butWasSupposedTo($workspace->workspaceName);
        }

        return $baseWorkspace;
    }
}
