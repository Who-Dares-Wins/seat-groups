<div class="col-md-4">

  <div class="box box-warning">
    <div class="box-header with-border">
      @includeWhen(auth()->user()->hasRole('seatgroups.create'),'seatgroups::partials.edit-button')
      <h3 class="box-title">{{$seatgroup->name}}</h3>
    </div>
    <div class="box-body">
      @includeWhen($seatgroup->isQualified(auth()->user()->group),'seatgroups::partials.join-button')
      {{$seatgroup->description}} <br>
      @if($seatgroup->isMember(auth()->user()->group))
        Members: {{$seatgroup->member->map(function($group) { return $group->main_character->name;})->implode(', ')}}
      @endif


    </div>
    <div class="box-footer">
      Managers: {{$seatgroup->manager->map(function($user) { return $user->main_character->name; })->concat($seatgroup->children->map(function($children) { return $children->name; }))->implode(', ')}}

      @includeWhen($seatgroup->isManager(auth()->user()->group) || auth()->user()->hasSuperUser(),'seatgroups::partials.manager-modal')

    </div>

  </div>
</div>


