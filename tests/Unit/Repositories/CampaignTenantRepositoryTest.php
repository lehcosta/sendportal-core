<?php

namespace Tests\Unit\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sendportal\Base\Models\Campaign;
use Sendportal\Base\Models\CampaignStatus;
use Sendportal\Base\Models\EmailService;
use Sendportal\Base\Models\Message;
use Sendportal\Base\Models\Subscriber;
use Sendportal\Base\Models\User;
use Sendportal\Base\Models\Workspace;
use Sendportal\Base\Repositories\Campaigns\CampaignTenantRepositoryInterface;
use Tests\TestCase;

class CampaignTenantRepositoryTest extends TestCase
{
    use RefreshDatabase;

    /** @var CampaignTenantRepositoryInterface */
    protected $campaignRepository;

    public function setUp(): void
    {
        parent::setUp();

        $this->campaignRepository = app(CampaignTenantRepositoryInterface::class);
    }

    /** @test */
    public function the_get_average_time_to_open_method_returns_the_average_time_taken_to_open_a_campaigns_message()
    {
        [$workspace, $emailService] = $this->createUserWithWorkspaceAndEmailService();
        $campaign = $this->createCampaign($workspace, $emailService);

        // 30 seconds
        $this->createOpenedMessages($workspace, $campaign, 1, [
            'delivered_at' => now(),
            'opened_at' => now()->addSeconds(30),
        ]);

        // 60 seconds
        $this->createOpenedMessages($workspace, $campaign, 1, [
            'delivered_at' => now(),
            'opened_at' => now()->addSeconds(60),
        ]);

        $averageTimeToOpen = $this->campaignRepository->getAverageTimeToOpen($campaign);

        // 45 seconds
        static::assertEquals('00:00:45', $averageTimeToOpen);
    }

    /** @test */
    public function the_get_average_time_to_open_method_returns_na_if_there_have_been_no_opens()
    {
        [$workspace, $emailService] = $this->createUserWithWorkspaceAndEmailService();
        $campaign = $this->createCampaign($workspace, $emailService);

        $averageTimeToOpen = $this->campaignRepository->getAverageTimeToOpen($campaign);

        static::assertEquals('N/A', $averageTimeToOpen);
    }

    /** @test */
    public function the_get_average_time_to_click_method_returns_the_average_time_taken_for_a_campaign_link_to_be_clicked_for_the_first_time()
    {
        [$workspace, $emailService] = $this->createUserWithWorkspaceAndEmailService();
        $campaign = $this->createCampaign($workspace, $emailService);

        // 30 seconds
        $this->createClickedMessages($workspace, $campaign, 1, [
            'delivered_at' => now(),
            'clicked_at' => now()->addSeconds(30),
        ]);

        // 30 seconds
        $this->createClickedMessages($workspace, $campaign, 1, [
            'delivered_at' => now(),
            'clicked_at' => now()->addSeconds(60),
        ]);

        $averageTimeToClick = $this->campaignRepository->getAverageTimeToClick($campaign);

        static::assertEquals('00:00:45', $averageTimeToClick);
    }

    /** @test */
    public function the_average_time_to_click_attribute_returns_na_if_there_have_been_no_clicks()
    {
        [$workspace, $emailService] = $this->createUserWithWorkspaceAndEmailService();
        $campaign = $this->createCampaign($workspace, $emailService);

        $averageTimeToClick = $this->campaignRepository->getAverageTimeToClick($campaign);

        static::assertEquals('N/A', $averageTimeToClick);
    }

    /** @test */
    public function the_cancel_campaign_method_sets_the_campaign_status_to_cancelled()
    {
        $campaign = factory(Campaign::class)->state('queued')->create();

        static::assertEquals(CampaignStatus::STATUS_QUEUED, $campaign->status_id);
        $success = $this->campaignRepository->cancelCampaign($campaign);

        static::assertTrue($success);
        static::assertEquals(CampaignStatus::STATUS_CANCELLED, $campaign->fresh()->status_id);
    }

    /** @test */
    public function the_cancel_campaign_method_deletes_draft_messages_if_the_campaign_has_any()
    {
        [$workspace, $emailService] = $this->createUserWithWorkspaceAndEmailService();

        $campaign = factory(Campaign::class)->states(['withContent', 'sent'])->create([
            'workspace_id' => $workspace->id,
            'email_service_id' => $emailService->id,
            'save_as_draft' => 1,
        ]);
        $this->createPendingMessages($workspace, $campaign, 1);

        static::assertCount(1, Message::all());

        $this->campaignRepository->cancelCampaign($campaign);

        static::assertCount(0, Message::all());
    }

    /** @test */
    public function the_cancel_campaign_method_does_not_delete_sent_messages()
    {
        [$workspace, $emailService] = $this->createUserWithWorkspaceAndEmailService();

        $campaign = factory(Campaign::class)->states(['withContent', 'sent'])->create([
            'workspace_id' => $workspace->id,
            'email_service_id' => $emailService->id,
            'save_as_draft' => 1,
        ]);
        $this->createOpenedMessages($workspace, $campaign, 1);

        static::assertCount(1, Message::all());

        $this->campaignRepository->cancelCampaign($campaign);

        static::assertCount(1, Message::all());
    }

    /** @test */
    public function the_get_count_method_returns_campaign_message_counts()
    {
        [$workspace, $emailService] = $this->createUserWithWorkspaceAndEmailService();
        $campaign = $this->createCampaign($workspace, $emailService);

        $expectedOpenedMessages = 1;
        $expectedUnopenedMessages = 2;
        $expectedClickedMessages = 3;
        $expectedBouncedMessages = 4;
        $expectedPendingMessages = 5;

        $this->createOpenedMessages($workspace, $campaign, $expectedOpenedMessages);
        $this->createUnopenedMessages($workspace, $campaign, $expectedUnopenedMessages);
        $this->createClickedMessages($workspace, $campaign, $expectedClickedMessages);
        $this->createBouncedMessages($workspace, $campaign, $expectedBouncedMessages);
        $this->createPendingMessages($workspace, $campaign, $expectedPendingMessages);

        $counts = $this->campaignRepository->getCounts(collect($campaign->id), $workspace->id);

        $totalSentCount = $expectedOpenedMessages
            + $expectedClickedMessages
            + $expectedUnopenedMessages
            + $expectedBouncedMessages;

        static::assertEquals($expectedOpenedMessages, $counts[$campaign->id]->opened);
        static::assertEquals($expectedClickedMessages, $counts[$campaign->id]->clicked);
        static::assertEquals($totalSentCount, $counts[$campaign->id]->sent);
        static::assertEquals($expectedBouncedMessages, $counts[$campaign->id]->bounced);
        static::assertEquals($expectedPendingMessages, $counts[$campaign->id]->pending);
    }

    /**
     * @return array
     */
    protected function createUserWithWorkspaceAndEmailService(): array
    {
        $user = factory(User::class)->create();
        $workspace = factory(Workspace::class)->create([
            'owner_id' => $user->id,
        ]);
        $emailService = factory(EmailService::class)->create([
            'workspace_id' => $workspace->id,
        ]);

        return [$workspace, $emailService];
    }

    /**
     * @param Workspace $workspace
     * @param EmailService $emailService
     *
     * @return Campaign
     */
    protected function createCampaign(Workspace $workspace, EmailService $emailService): Campaign
    {
        return factory(Campaign::class)->states(['withContent', 'sent'])->create([
            'workspace_id' => $workspace->id,
            'email_service_id' => $emailService->id,
        ]);
    }

    /**
     * @return Collection|Model|mixed
     */
    protected function createOpenedMessages(Workspace $workspace, Campaign $campaign, int $quantity = 1, array $overrides = [])
    {
        $data = array_merge([
            'workspace_id' => $workspace->id,
            'subscriber_id' => factory(Subscriber::class)->create([
                'workspace_id' => $workspace->id,
            ]),
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'open_count' => 1,
            'sent_at' => now(),
            'delivered_at' => now(),
            'opened_at' => now(),
        ], $overrides);

        return factory(Message::class, $quantity)->create($data);
    }

    /**
     * @return Collection|Model|mixed
     */
    protected function createUnopenedMessages(Workspace $workspace, Campaign $campaign, int $count)
    {
        return factory(Message::class, $count)->create([
            'workspace_id' => $workspace->id,
            'subscriber_id' => factory(Subscriber::class)->create([
                'workspace_id' => $workspace->id,
            ]),
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'open_count' => 0,
            'sent_at' => now(),
            'delivered_at' => now(),
        ]);
    }

    /**
     * @return Collection|Model|mixed
     */
    protected function createClickedMessages(Workspace $workspace, Campaign $campaign, int $quantity = 1, array $overrides = [])
    {
        $data = array_merge([
            'workspace_id' => $workspace->id,
            'subscriber_id' => factory(Subscriber::class)->create([
                'workspace_id' => $workspace->id,
            ]),
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'click_count' => 1,
            'sent_at' => now(),
            'delivered_at' => now(),
            'clicked_at' => now(),
        ], $overrides);

        return factory(Message::class, $quantity)->create($data);
    }

    /**
     * @return Collection|Model|mixed
     */
    protected function createUnclickedMessage(Workspace $workspace, Campaign $campaign, int $count)
    {
        return factory(Message::class, $count)->create([
            'workspace_id' => $workspace->id,
            'subscriber_id' => factory(Subscriber::class)->create([
                'workspace_id' => $workspace->id,
            ]),
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'click_count' => 0,
            'sent_at' => now(),
            'delivered_at' => now(),
        ]);
    }

    /**
     * @return Collection|Model|mixed
     */
    protected function createBouncedMessages(Workspace $workspace, Campaign $campaign, int $count)
    {
        return factory(Message::class, $count)->create([
            'workspace_id' => $workspace->id,
            'subscriber_id' => factory(Subscriber::class)->create([
                'workspace_id' => $workspace->id,
            ]),
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'sent_at' => now(),
            'bounced_at' => now(),
        ]);
    }

    /**
     * @return Collection|Model|mixed
     */
    protected function createPendingMessages(Workspace $workspace, Campaign $campaign, int $count)
    {
        return factory(Message::class, $count)->create([
            'workspace_id' => $workspace->id,
            'subscriber_id' => factory(Subscriber::class)->create([
                'workspace_id' => $workspace->id,
            ]),
            'source_type' => Campaign::class,
            'source_id' => $campaign->id,
            'sent_at' => null,
        ]);
    }
}
