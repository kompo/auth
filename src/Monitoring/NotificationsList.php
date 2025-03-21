<?php

namespace Kompo\Auth\Monitoring;

use Illuminate\Support\Carbon;
use Kompo\Auth\Facades\NotificationModel;
use Kompo\Query;

class NotificationsList extends Query
{
    public $paginationType = 'Scroll';
    public $itemsWrapperClass = 'overflow-y-auto mini-scroll relative z-1 pt-4 pb-20';
    public $itemsWrapperStyle = 'max-height: 450px';

    public function noItemsFound()
    {
        return _Html('dashboard-no-notifications')->icon('icon-check')
            ->class('text-white text-sm');
    }

    public function query()
    {
        return NotificationModel::whereNotNull('type')->with('about')
            ->where('team_id', currentTeam()->id)
            ->where('user_id', auth()->user()->id)
            ->where(function($q){
                $q->whereNull('status')
                    ->orWhere(function($q){
                        $q->whereNotNull('reminder_at')
                          ->where('reminder_at', '<=', Carbon::now());
                    });
            })
            ->latest();
    }

    public function render($notification, $key)
    {
        return $notification->notificationCard($key); //key => decreasing z-index => dropdown button don't get blurred
    }


    public function approveReview($id)
    {
        $this->handleReview($id, 'approve');
    }

    public function rejectReview($id)
    {
        $this->handleReview($id, 'reject');
    }

    public function handleReview($id, $action)
    {
        if ($review = Review::find($id)) {

            if ( auth()->user()->can('update', $review) && !$review->isReviewed()) {
                $review->{$action}();
            }

            $review->delete();
        }
    }

    public function notifyWaterHeaterExpiry($notificationId, $unitId)
    {
        $unit = Unit::with('union')->find($unitId);
        $owner = $unit->mainOwnerWithEmail();

        if (!$owner) {
            abort(404);
        }

        $expiryMessage = __('Hi').' '.$owner->name.',<br><br> '.__('email.the-water-heater1').' <b>'.$unit->display.' - '.$unit->union->display.'</b> '.__('email.the-water-heater2').'<br><br> '.__('email.the-water-heater3').'<br><br>'.__('email.thank-you');

        $message = ThreadMaker::createFromParts(
            currentTeam()->id,
            $unit->union->id,
            $unit->id,
            auth()->user(),
            collect([$owner]),
            __('email.water-heater-expiry-notice'),
            $expiryMessage,
            $expiryMessage,
        );

        $message->sendExternalEmail($owner->user ?
            route('unit.view', ['id' => $unit->id]) :
            $owner->invitationUrl()
        );

        NotificationModel::find($notificationId)->delete();

        return __('monitoring-notification-sent');
    }

    public function notifyMissingInfoContacts($teamId)
    {
        Contact::withMissingInfos(Team::findOrFail($teamId))->with('emails')->get()->each(function($contact) {

            if ($contact->mainEmail()) {

                $message = new Message();
                $message->sender_id = currentMailboxId();
                $message->subject = __('email-message-from').' '.$contact->units()->first()->union->display;
                $message->html = '<p>'.__('general-hi').' '.$contact->first_name.'</p>'.
                    '<p>'.__('email-missing-informations').'</p>';

                Mail::to($contact->mainEmail())->queue(
                    new CommunicationNotification($message, $contact->invitationUrl())
                );
            }
        });
    }

    public function sendCurrentMonthContributions($budgetId)
    {
        $budget = Budget::find($budgetId);

        $firstDate = $budget->invoices()->draft()->orderBy('invoiced_at')->value('invoiced_at');

        $budget->invoices()->draft()->where('customer_type', 'unit')->forDate($firstDate)
            ->with('customer.owners.emails')->get()->each(function($invoice){

                $invoice->sendEmail();

                //TODO: important if no email is attached the owner is not notified. Need to make sure they all have emails...
        });
    }

    public function closeProject($projectId)
    {
        Project::findOrFail($projectId)->task->close();
    }

}
