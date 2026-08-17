<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CreativeWritingPromptSeeder extends Seeder
{
    public function run(): void
    {
        $prompts=$this->prompts();
        Tenant::query()->pluck('id')->each(function(int $tenantId)use($prompts){
            foreach($prompts as $index=>$prompt){
                DB::table('creative_writing_prompts')->insertOrIgnore([
                    ...$prompt,'tenant_id'=>$tenantId,'active'=>true,'source_type'=>'starter_library','source_key'=>'starter-'.($index+1),'created_by_user_id'=>null,'updated_at'=>now(),'created_at'=>now(),
                ]);
            }
        });
    }

    private function prompts(): array
    {
        return [
            ['title'=>'Gravity Stops Working','prompt'=>'You wake up and discover gravity has stopped working. What happens during your morning?','include_hints'=>json_encode(['Where you are','What starts floating','How your family reacts','One problem you have to solve','How the morning ends']),'category'=>'Unexpected Adventures'],
            ['title'=>'The Talking Dog','prompt'=>'Your dog suddenly learns how to talk. What is the very first thing they say, and what secrets do they know about your family?','include_hints'=>json_encode(["The dog's first words",'Your reaction','One funny secret','One thing the dog wants','What happens next']),'category'=>'Funny Fiction'],
            ['title'=>'Aliens Need Your Help','prompt'=>'Aliens land in your backyard, but they are not here to invade Earth. They need your help with something ridiculous. What is it?','include_hints'=>json_encode(['What the aliens look like','How they arrive','What they need help with','Why they picked you','Whether you succeed']),'category'=>'Space Adventures'],
            ['title'=>'Design the Ultimate Video Game','prompt'=>'You are hired to design the ultimate video game. Describe the world, the characters, the goal, and the coolest thing players can do.','include_hints'=>json_encode(['The name of the game','Where it takes place','The main character','The main challenge','A special power or item','How someone wins']),'category'=>'Invent and Design'],
            ['title'=>'The Neighborhood Dragon','prompt'=>'A dragon moves into your neighborhood. Everyone is nervous, but the dragon insists it is a good neighbor. What happens?','include_hints'=>json_encode(['What the dragon looks like','Where it lives','Something that scares the neighbors','Something nice the dragon does','Whether everyone eventually accepts it']),'category'=>'Fantasy'],
            ['title'=>'The Pizza Machine','prompt'=>'You accidentally invent a machine that can turn anything into pizza. At first this seems awesome. Then something goes very wrong.','include_hints'=>json_encode(['How you invented it','The first thing you turn into pizza','What goes wrong','How big the problem becomes','How you fix it']),'category'=>'Invent and Design'],
            ['title'=>'A Week on a New Planet','prompt'=>'NASA chooses you to spend one week on a newly discovered planet. What does the planet look like, and what strange things do you discover?','include_hints'=>json_encode(["The planet's name",'What the sky and ground look like','One strange creature','One surprising discovery','Your favorite part of the trip']),'category'=>'Space Adventures'],
            ['title'=>'DO NOT PUSH','prompt'=>'You find a button with a sign that says, "DO NOT PUSH." Obviously, somebody is going to push it. What happens when you do?','include_hints'=>json_encode(['Where you find the button','Why you decide to push it','What happens immediately','One unexpected problem','How things turn out']),'category'=>'Unexpected Adventures'],
            ['title'=>'Adults Become 10 Years Old','prompt'=>'Every adult in the world suddenly becomes 10 years old for one day. What happens at your house?','include_hints'=>json_encode(['How you discover what happened','What the adults do first','Something funny that happens','One problem caused by having no adults','How the day ends']),'category'=>'Funny Fiction'],
            ['title'=>'Create a New School Rule','prompt'=>'You are allowed to create one new school rule that everyone must follow. What is your rule, and why should it exist?','include_hints'=>json_encode(['The new rule','Why you chose it','How students react','How teachers react','One good thing that happens because of it','One possible problem']),'category'=>'Imagine and Explain'],
            ['title'=>'Cafeteria Zombie Apocalypse','prompt'=>'A zombie apocalypse begins during lunchtime. You can only use things found in a school cafeteria to survive. What is your plan?','include_hints'=>json_encode(['How you realize zombies are coming','What supplies you grab','Where you hide or escape','Who is with you','How your plan works']),'category'=>'Action Adventures'],
            ['title'=>'The Secret Room','prompt'=>'You discover a secret room hidden inside your house. Where is the entrance, what is inside, and why has nobody found it before?','include_hints'=>json_encode(['How you find the entrance','What the room looks like','Three things inside','One mystery you discover','What you decide to do about it']),'category'=>'Mystery'],
            ['title'=>'Your Favorite Game Becomes Real','prompt'=>'Your favorite game becomes real for 24 hours. You are now inside its world. What do you do first?','include_hints'=>json_encode(['Which game world you enter','What you look like inside the game','Who you meet','One challenge you face','Whether you want to leave when the 24 hours are over']),'category'=>'Game Worlds'],
            ['title'=>'The Tiny Civilization','prompt'=>'A tiny civilization lives underneath your bed. They finally decide to introduce themselves. What are they like, and what do they want?','include_hints'=>json_encode(['What the tiny people look like','How long they have been there','What their city looks like','Why they finally talk to you','What they want from you']),'category'=>'Fantasy'],
            ['title'=>'Spend $1 Million in 24 Hours','prompt'=>'You are given $1 million, but you must spend all of it in 24 hours. You cannot save it. What do you buy or do with the money?','include_hints'=>json_encode(['The first thing you buy','Something fun','Something for someone else','Something completely ridiculous','Whether spending all the money is harder than you expected']),'category'=>'Big Choices'],
            ['title'=>'The Overachieving Robot','prompt'=>'You build a robot to do one annoying chore for you. Unfortunately, the robot takes its job WAY too seriously. What happens?','include_hints'=>json_encode(["The robot's name",'What chore it is supposed to do','How it does the job at first','What goes wrong','How you stop or fix the robot']),'category'=>'Funny Fiction'],
            ['title'=>'Your Teacher Is a Superhero','prompt'=>'You discover that your teacher is secretly a superhero. What is their superhero name, power, and secret mission?','include_hints'=>json_encode(['How you discover the secret','The superhero name','The special power','The villain or problem they are fighting','Whether you help with the mission']),'category'=>'Superhero Adventures'],
            ['title'=>'Discover a New Animal','prompt'=>'Scientists discover a new animal and let you name it. Describe what it looks like, what it eats, where it lives, and the name you give it.','include_hints'=>json_encode(['Its name','Size','Appearance','Habitat','Food','One strange ability','Whether it is friendly or dangerous']),'category'=>'Discoveries'],
            ['title'=>'Create Your Own Planet','prompt'=>'You can create your own planet. Describe its name, weather, animals, food, cities, rules, and anything else that makes it awesome.','include_hints'=>json_encode(["The planet's name",'What it looks like from space','The weather','Creatures that live there','What people eat','One unusual law','The coolest place to visit']),'category'=>'Space Adventures'],
            ['title'=>'One Trip in a Time Machine','prompt'=>'A time machine appears in your room. You can travel to any time in the past or future, but you only get one trip. Where do you go, and what happens?','include_hints'=>json_encode(['Where and when you travel','Why you chose that time','Who or what you see','One surprising event','Whether you make it safely back home']),'category'=>'Time Travel'],
        ];
    }
}
