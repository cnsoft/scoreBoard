# Create your views here.
from django.shortcuts import render_to_response
from scorebrd.models import Team, Event

def events(request):
    events = Event.objects.all()
    return render_to_response('events.html', {'events': events})

def teams(request):
    teams = Team.objects.all()
    return render_to_response('teams.html', {'teams': teams})


def index(request):
    return render_to_response('index.html')
