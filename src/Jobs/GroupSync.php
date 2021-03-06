<?php
/**
 * Created by PhpStorm.
 * User: felix
 * Date: 01.09.2018
 * Time: 16:16.
 */

namespace Herpaderpaldent\Seat\SeatGroups\Jobs;

use Herpaderpaldent\Seat\SeatGroups\Exceptions\MissingRefreshTokenException;
use Herpaderpaldent\Seat\SeatGroups\Models\Seatgroup;
use Herpaderpaldent\Seat\SeatGroups\Models\SeatgroupLog;
use Illuminate\Support\Facades\Redis;
use Seat\Web\Models\Group;

class GroupSync extends SeatGroupsJobBase
{
    /**
     * @var array
     */
    protected $tags = ['sync'];

    /**
     * @var \Seat\Web\Models\Group
     */
    private $group;

    /**
     * @var \Seat\Eveapi\Models\Character\CharacterInfo
     */
    private $main_character;

    /**
     * @var int
     */
    public $tries = 1;

    /**
     * ConversationOrchestrator constructor.
     *
     * @param \Seat\Web\Models\Group $group
     */
    public function __construct(Group $group)
    {
        $this->group = $group;
        $this->main_character = $group->main_character;
        if (is_null($group->main_character)) {
            logger()->warning('Group has no main character set. Attempt to make assignation based on first attached character.', [
                'group_id' => $group->id,
            ]);
            $this->main_character = $group->users->first()->character;
        }

        // avoid the construct to throw an exception if no character has been set
        if (! is_null($this->main_character)) {
            logger()->debug('Initialising SeAT Group sync for ' . $this->main_character->name);

            array_push($this->tags, sprintf('users: %s',
                $this->group->users->map(function ($user) { return $user->name; })->implode(', ')));
        }

    }

    public function handle()
    {
        // in case no main character has been set, throw an exception and abort the process
        if (is_null($this->main_character))
            throw new MissingMainCharacterException($this->group);

        Redis::funnel('seat-groups:jobs.group_sync_' . $this->group->id)->limit(1)->then(function ()
        {
            $this->beforeStart();

            try {
                $roles = collect();
                $group = $this->group;

                //Catch Superuser
                foreach ($group->roles as $role) {
                    if ($role->title === 'Superuser') {
                        $roles->push($role->id);
                    }
                }

                Seatgroup::all()->each(function ($seat_group) use ($roles, $group) {

                    if ($seat_group->isQualified($group)) {
                        switch ($seat_group->type) {
                            case 'auto':
                                foreach ($seat_group->role as $role) {
                                    $roles->push($role->id);
                                }
                                if(! in_array($group->id, $seat_group->group->pluck('id')->toArray())){
                                    // add user_group to seat_group as member if no member yet.
                                    $seat_group->member()->attach($group->id);
                                }
                                break;
                            case 'open':
                            case 'managed':
                            case 'hidden':
                                // check if user is in the group
                                if ($seat_group->isMember($group)) {
                                    foreach ($seat_group->role as $role) {
                                        $roles->push($role->id);
                                    }
                                }
                                break;
                        }
                    } elseif(in_array($group->id, $seat_group->group->pluck('id')->toArray())) {
                        $seat_group->member()->detach($group->id);
                    }
                });

                $group->roles()->sync($roles->unique());

                $this->onFinish();

                logger()->debug('Group has beend synced for ' . $this->main_character->name);

            } catch (\Throwable $exception) {

                $this->onFail($exception);

            }

        }, function ()
        {
            logger()->warning('A GroupSync job is already running for ' . $this->main_character->name . ' Removing the job from the queue.');

            $this->delete();
        });

    }

    public function beforeStart()
    {

        foreach ($this->group->users as $user) {

            // If a RefreshToken is missing
            if (is_null($user->refresh_token)) {
                // take away all roles
                $this->group->roles()->sync([]);
                Seatgroup::all()->each(function ($seatgroup) {
                    $seatgroup->member()->detach($this->group->id);
                });

                SeatgroupLog::create([
                    'event' => 'warning',
                    'message' => sprintf('The RefreshToken of %s is missing, therefore user group of %s (%s) loses all permissions.',
                        $user->name, $this->main_character->name, $this->group->users->map(function ($user) { return $user->name; })->implode(', ')),

                ]);

                // throw exception
                throw new MissingRefreshTokenException();
            }
        }
    }

    public function onFail($exception)
    {

        report($exception);

        SeatgroupLog::create([
            'event' => 'error',
            'message' => sprintf('An error occurred while syncing user group of %s (%s). Please check the logs.',
                $this->main_character->name, $this->group->users->map(function ($user) { return $user->name; })->implode(', ')),

        ]);

    }

    public function onFinish()
    {
        SeatgroupLog::create([
            'event' => 'success',
            'message' => sprintf('The user group of %s (%s) has successfully been synced.',
                $this->main_character->name, $this->group->users->map(function ($user) { return $user->name; })->implode(', ')),

        ]);
    }
}
