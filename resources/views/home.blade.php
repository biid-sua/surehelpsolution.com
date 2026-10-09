@extends('layouts.app')

@section('title', 'SureHelp Solutions - Professional Call Management Platform')
@section('description', 'Never miss a customer call again with SureHelp Solutions. Professional call answering service platform for growing businesses.')

@section('styles')
  <style>
    .py-120 {
      padding-top: 120px;
      padding-bottom: 120px;
    }

    @media (max-width: 991.98px) {
      .py-120 {
        padding-top: 80px;
        padding-bottom: 80px;
      }
    }

    @media (max-width: 767.98px) {
      .py-120 {
        padding-top: 60px;
        padding-bottom: 60px;
      }
    }

    .hero-container {
            padding-top: 130px;
            position: relative;
            background: linear-gradient(135deg, #1E3A8A 0%, #625ED0 50%, #4D8BCC 100%);
            overflow: hidden;
        }

        .hero-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 20%, rgba(79, 70, 229, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 80% 80%, rgba(124, 58, 237, 0.15) 0%, transparent 40%),
                radial-gradient(circle at 50% 50%, rgba(99, 102, 241, 0.1) 0%, transparent 60%);
            pointer-events: none;
        }

        .floating-shapes {
            top: 180px;
            position: relative;
            z-index: 1;
        }
        .features-section {
            position: relative;
            padding: 8rem 2rem;
            background: linear-gradient(135deg, #0f0f23 0%, #1a1a3e 50%, #2d1b69 100%);
            min-height: 100vh;
        }

        .features-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 25% 25%, rgba(79, 70, 229, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 75% 75%, rgba(236, 72, 153, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 50% 50%, rgba(59, 130, 246, 0.05) 0%, transparent 70%);
            pointer-events: none;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            position: relative;
            z-index: 2;
        }

        .section-header {
            text-align: center;
            margin-bottom: 5rem;
            opacity: 0;
            transform: translateY(30px);
            animation: fadeInUp 1s ease-out 0.2s forwards;
        }

        .section-title {
            font-size: clamp(2.5rem, 5vw, 4rem);
            font-weight: 800;
            background: linear-gradient(135deg, #fff 0%, #a855f7 50%, #ccb1be 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1.5rem;
            line-height: 1.2;
        }

        .section-subtitle {
            font-size: 1.25rem;
            color: rgba(255, 255, 255, 0.7);
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.6;
        }

        .compliance-strip {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.75rem;
            max-width: 100%;
            margin: 0 auto 1.75rem;
        }

        .compliance-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, 0.22);
            background: rgba(255, 255, 255, 0.08);
            color: rgba(255, 255, 255, 0.92);
            font-size: 0.95rem;
            font-weight: 600;
            letter-spacing: 0.01em;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        .compliance-pill--guarantee {
            border-color: rgba(52, 211, 153, 0.45);
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.18) 0%, rgba(79, 70, 229, 0.12) 100%);
            box-shadow: 0 0 20px rgba(16, 185, 129, 0.15);
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
            gap: 2rem;
            margin-top: 4rem;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 24px;
            padding: 2rem 2.5rem;
            position: relative;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.23, 1, 0.32, 1);
            cursor: pointer;
            opacity: 0;
            transform: translateY(50px);
            animation: slideInUp 0.8s ease-out forwards;
        }

        .feature-card:nth-child(1) { animation-delay: 0.1s; }
        .feature-card:nth-child(2) { animation-delay: 0.2s; }
        .feature-card:nth-child(3) { animation-delay: 0.3s; }
        .feature-card:nth-child(4) { animation-delay: 0.4s; }
        .feature-card:nth-child(5) { animation-delay: 0.5s; }
        .feature-card:nth-child(6) { animation-delay: 0.6s; }

        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.1) 0%, rgba(236, 72, 153, 0.1) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .feature-card::after {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(135deg, #142848, #5745d9, #293c60);
            border-radius: 24px;
            z-index: -1;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .feature-card:hover::before {
            opacity: 1;
        }

        .feature-card:hover::after {
            opacity: 1;
        }

        .feature-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
        }

        .feature-icon-container {
            position: relative;
            margin-bottom: 2rem;
        }

        .feature-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #ccb1be 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: white;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .feature-icon::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }

        .feature-card:hover .feature-icon {
            transform: rotate(10deg) scale(1.1);
            box-shadow: 0 15px 30px rgba(79, 70, 229, 0.4);
        }

        .feature-card:hover .feature-icon::before {
            left: 100%;
        }

        .feature-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
            margin-bottom: 1rem;
            transition: color 0.3s ease;
        }

        .feature-card:hover .feature-title {
            background: linear-gradient(135deg, #fff 0%, #a855f7 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .feature-description {
            color: rgba(255, 255, 255, 0.7);
            line-height: 1.6;
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .feature-list {
            list-style: none;
        }

        .feature-list-item {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
            padding: 0.5rem 0;
            position: relative;
            transition: all 0.3s ease;
            border-radius: 8px;
        }

        .feature-list-item:hover {
            background: rgba(255, 255, 255, 0.05);
            padding-left: 1rem;
        }

        .check-icon {
            width: 24px;
            height: 24px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 0.8rem;
            color: white;
            transition: all 0.3s ease;
            flex-shrink: 0;
        }

        .feature-list-item:hover .check-icon {
            transform: scale(1.2);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        }

        .feature-list-text {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.95rem;
            transition: color 0.3s ease;
        }

        .feature-list-item:hover .feature-list-text {
            color: white;
        }

        .floating-elements {
            position: absolute;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 1;
        }

        .floating-circle {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            animation: float 8s ease-in-out infinite;
        }

        .floating-circle:nth-child(1) {
            width: 60px;
            height: 60px;
            top: 10%;
            left: 5%;
            animation-delay: 0s;
        }

        .floating-circle:nth-child(2) {
            width: 40px;
            height: 40px;
            top: 70%;
            right: 10%;
            animation-delay: 2s;
            animation-duration: 6s;
        }

        .floating-circle:nth-child(3) {
            width: 80px;
            height: 80px;
            bottom: 15%;
            left: 15%;
            animation-delay: 4s;
            animation-duration: 10s;
        }

        .demo-button {
            display: inline-block;
            margin-top: 0.5rem;
            padding: 0.8rem 2rem;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s ease;
            font-size: 0.9rem;
            position: relative;
            overflow: hidden;
        }

        .demo-button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s;
        }

        .demo-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(79, 70, 229, 0.4);
        }

        .demo-button:hover::before {
            left: 100%;
        }

        .interactive-showcase {
            margin-top: 6rem;
            text-align: center;
        }

        .showcase-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 2rem;
            margin-top: 3rem;
        }

        .showcase-item {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 16px;
            padding: 2rem 1rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .showcase-item:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-5px);
        }

        .showcase-number {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, #4f46e5 0%, #ccb1be 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.5rem;
        }

        .showcase-label {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.9rem;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .features-grid {
                grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
                gap: 1.5rem;
            }
            
            .feature-card {
                padding: 2.5rem 2rem;
            }
        }

        @media (max-width: 768px) {
            .features-section {
                padding: 4rem 1rem;
            }
            
            .features-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
            
            .feature-card {
                padding: 2rem 1.5rem;
            }
            
            .section-title {
                font-size: 2.5rem;
            }
            
            .section-subtitle {
                font-size: 1.1rem;
            }

            .compliance-strip {
                gap: 0.55rem;
                margin-bottom: 1.25rem;
            }

            .compliance-pill {
                font-size: 0.82rem;
                padding: 0.4rem 0.7rem;
            }
            
            .showcase-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .features-section {
                padding: 3rem 1rem;
            }
            
            .feature-card {
                padding: 1.5rem 1rem;
            }
            
            .feature-icon {
                width: 60px;
                height: 60px;
                font-size: 1.5rem;
            }
            
            .feature-title {
                font-size: 1.25rem;
            }
            
            .showcase-grid {
                grid-template-columns: 1fr;
            }

            .timeline-step {
                padding: 2rem 1rem!important;
                margin: 0!important;
            }
        }
        /* How It Works */
        .how-it-works-section {
            position: relative;
            background: linear-gradient(135deg, #0f0f23 0%, #1a1a3e 50%, #2d1b69 100%);
            color: white;
            overflow: hidden;
        }
        .how-it-works-section::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: radial-gradient(circle at 25% 25%, rgba(79,70,229,.1) 0%, transparent 50%),
                        radial-gradient(circle at 75% 75%, rgba(236,72,153,.1) 0%, transparent 50%);
            pointer-events: none;
        }
        .timeline-container { position: relative; max-width: 100%; margin: 4rem auto; padding: 0; display: flex; justify-content: space-between; align-items: flex-start; }
        .timeline-container::before { content: ''; position: absolute; top: 40px; left: 50px; right: 50px; height: 4px; background: linear-gradient(90deg, rgba(79,70,229,.3) 0%, rgba(124,58,237,.3) 50%, rgba(236,72,153,.3) 100%); z-index: 0; }
        .timeline-step { position: relative; flex: 1; max-width: 350px; margin: 0 1rem; opacity: 0; transform: translateY(30px); animation: fadeInUp 0.6s ease-out forwards; z-index: 1; }
        .timeline-icon { position: relative; margin: 0 auto 2rem; width: fit-content; }
        .icon-circle { width: 80px; height: 80px; background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #ccb1be 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2rem; color: white; position: relative; transition: all 0.3s ease; margin: 0 auto; box-shadow: 0 10px 20px rgba(0,0,0,.2); }
        .icon-circle::before { content: ''; position: absolute; top: -5px; left: -5px; right: -5px; bottom: -5px; background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #ccb1be 100%); border-radius: 50%; z-index: -1; opacity: .3; transition: all .3s ease; }
        .timeline-step:hover .icon-circle::before { transform: scale(1.2); }
        .step-number { position: absolute; top: -10px; right: -10px; width: 35px; height: 35px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #4f46e5; border: 3px solid #4f46e5; z-index: 2; }
        .timeline-content { background: rgba(255,255,255,.05); border-radius: 20px; padding: 2rem 1rem; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,.1); transition: all .3s ease; text-align: center; min-height: 100px; }
        .timeline-content:hover { transform: translateY(-5px); background: rgba(255,255,255,.08); box-shadow: 0 20px 40px rgba(0,0,0,.2); }
        .timeline-content h3 { font-size: 1.5rem; margin-bottom: 1.5rem; color: white; }
        .timeline-list { list-style: none; padding: 0; margin: 0; text-align: left; }
        .timeline-list li { display: flex; align-items: center; margin-bottom: 1rem; padding: .5rem; border-radius: 10px; transition: all .3s ease; }
        .timeline-list li:hover { background: rgba(255,255,255,.05); transform: translateX(10px); }
        @media (max-width: 991px) { .timeline-container { flex-direction: column; align-items: center; } .timeline-container::before{display:none;} .timeline-step { width:100%; padding:2rem 1.5rem; margin:3rem 0; } .timeline-list {max-width:400px; margin:0 auto;} }

        /* Testimonials */
        .testimonials-section { position: relative; background: linear-gradient(135deg, #0f0f23 0%, #1a1a3e 50%, #2d1b69 100%); color: white; overflow: hidden; padding-bottom: 60px; }
        .testimonials-section::before { content: ''; position: absolute; top:0; left:0; right:0; bottom:0; background: radial-gradient(circle at 25% 25%, rgba(79,70,229,.1) 0%, transparent 50%), radial-gradient(circle at 75% 75%, rgba(236,72,153,.1) 0%, transparent 50%); pointer-events:none; }
        .testimonials-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 2rem; margin: 4rem 0; }
        .testimonial-card { background: rgba(255,255,255,.05); border-radius: 20px; padding: 2rem; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,.1); transition: all .3s ease; }
        .testimonial-card:hover { transform: translateY(-10px); background: rgba(255,255,255,.08); box-shadow: 0 20px 40px rgba(0,0,0,.2); }
        .testimonial-header { display:flex; justify-content: space-between; align-items:flex-start; margin-bottom:1.5rem; }
        .client-info { display:flex; align-items:center; gap:1rem; }
        .client-image { width:60px; height:60px; border-radius:50%; overflow:hidden; border:3px solid rgba(79,70,229,.5); }
        .client-details h4 { font-size:1.1rem; margin:0; color:white; }
        .client-details p { font-size:.9rem; color: rgba(255,255,255,.7); margin:0; }
        .rating { color:#ffd700; font-size:1rem; }
        .testimonial-content { margin-bottom:1.5rem; font-size:1rem; line-height:1.6; color: rgba(255,255,255,.9); }
        .testimonial-results { display:flex; gap:1rem; flex-wrap:wrap; }
        .result-badge { background: linear-gradient(135deg, rgba(79,70,229,.2) 0%, rgba(236,72,153,.2) 100%); border-radius:50px; padding:.5rem 1rem; display:flex; align-items:center; gap:.5rem; font-size:.9rem; color:white; border:1px solid rgba(255,255,255,.1); transition: all .3s ease; }
        .result-badge:hover { transform: translateY(-2px); background: linear-gradient(135deg, rgba(79,70,229,.3) 0%, rgba(236,72,153,.3) 100%); }
        .testimonial-cta { text-align:center; margin-top:4rem; padding:3rem; background: rgba(255,255,255,.05); border-radius:20px; backdrop-filter: blur(10px); border:1px solid rgba(255,255,255,.1); }
        .testimonial-cta .cta-buttons { display:flex; justify-content:center; align-items:center; gap:1.25rem; flex-wrap:wrap; padding-top: .5rem; }
        .testimonial-cta .cta-button { padding: 0.9rem 1.5rem; }
        .new-company-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; margin: 2rem 0 0; }
        .new-company-item { background: rgba(255,255,255,.05); border-radius: 14px; padding: 1rem 1.1rem; border: 1px solid rgba(255,255,255,.1); text-align: left; color: rgba(255,255,255,.9); display: flex; align-items: center; gap: .6rem; }
        .new-company-item i { color: #34d399; }
        .new-company-note { margin-top: 1.1rem; color: rgba(255,255,255,.72); font-size: .95rem; }
        @media (max-width: 768px) {
          .testimonial-cta .cta-buttons { flex-direction: column; gap: .75rem; }
          .testimonial-cta .cta-button { width: 100%; max-width: 320px; }
        }

        /* Pricing */
        .pricing-section { position: relative; background: linear-gradient(135deg, #0f0f23 0%, #1a1a3e 50%, #2d1b69 100%); color: white; overflow: hidden; padding-bottom: 100px; }
        .pricing-section::before { content:''; position:absolute; top:0; left:0; right:0; bottom:0; background: radial-gradient(circle at 25% 25%, rgba(79,70,229,.1) 0%, transparent 50%), radial-gradient(circle at 75% 75%, rgba(236,72,153,.1) 0%, transparent 50%); pointer-events:none; }
        .pricing-toggle { margin-top: 1.25rem; }
        .toggle-container { display: inline-flex; align-items: center; gap: 1rem; padding: .5rem; background: rgba(255,255,255,.05); border-radius: 50px; position: relative; }
        .save-badge { position: absolute; top: -10px; right: -20px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: .25rem .75rem; border-radius: 20px; font-size: .8rem; font-weight: 600; }
        .switch { position: relative; display: inline-block; width: 60px; height: 30px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,.1); transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 24px; width: 24px; left: 3px; bottom: 3px; background: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); }
        input:checked + .slider:before { transform: translateX(30px); }
        .pricing-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: clamp(0.75rem, 1.5vw, 1.125rem); margin: 1.75rem 0 2rem; position: relative; z-index: 1; align-items: start; }
        @media (max-width: 1199.98px) { .pricing-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 575.98px) { .pricing-grid { grid-template-columns: 1fr; max-width: 440px; margin-left: auto; margin-right: auto; } }
        .pricing-card { background: rgba(255,255,255,.05); border-radius: 20px; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,.1); transition: all .3s ease; position: relative; overflow: hidden; display: flex; flex-direction: column; height: auto; min-height: 0; }
        .pricing-card:hover { transform: translateY(-6px); background: rgba(255,255,255,.08); box-shadow: 0 20px 40px rgba(0,0,0,.2); }
        .pricing-card.popular { border: 2px solid rgba(79,70,229,.55); box-shadow: 0 0 0 1px rgba(79,70,229,.25), 0 12px 40px rgba(79,70,229,.2); z-index: 1; transform: none; }
        .pricing-card.popular:hover { transform: translateY(-6px); box-shadow: 0 0 0 1px rgba(79,70,229,.3), 0 18px 48px rgba(79,70,229,.28); }
        .card-header { padding: 0.8rem 0rem; text-align: center; border-bottom: 1px solid rgba(255,255,255,.1); flex-shrink: 0; }
        .plan-name { font-size: 1.5rem; font-weight: 700; margin-bottom: 1rem; background: linear-gradient(135deg, #fff 0%, #a855f7 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .price-monthly,.price-annual { align-items: baseline; justify-content: center; gap: .25rem; }
        .currency { font-size: 1.5rem; font-weight: 600; color: white; }
        .amount { font-size: 3rem; font-weight: 700; color: white; line-height: 1; }
        .period { color: rgba(255,255,255,.7); }
        .plan-description { color: rgba(255,255,255,.7); font-size: .9rem; margin: 0; }
        .plan-tier-label { font-size: .72rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: rgba(196, 181, 253, .95); margin: -0.5rem 0 1rem; }
        .plan-description-extended { display: block; margin-top: .65rem; font-size: .85rem; line-height: 1.55; color: rgba(255,255,255,.58); }
        .price-custom-display { align-items: baseline; justify-content: center; gap: .25rem; }
        .pricing-card-enterprise { border: 1px solid rgba(167, 139, 250, .35); }
        .pricing-card .feature-list { flex: 1 1 auto; padding: 1rem 1rem; min-height: 0; }
        .feature-item { display: flex; align-items: flex-start; gap: .65rem; margin-bottom: .55rem; color: rgba(255,255,255,.9); transition: all .3s ease; font-size: .92rem; line-height: 1.45; }
        .pricing-card .feature-item:last-child { margin-bottom: 0; }
        .feature-item i { color: #10b981; font-size: 1.2rem; flex-shrink: 0; margin-top: .1em; }
        .feature-item:hover { transform: translateX(5px); color: white; }
        .feature-more { margin-top: .1rem; }
        .feature-more-content { max-height: 0; opacity: 0; transform: translateY(-6px); overflow: hidden; transition: max-height .35s ease, opacity .28s ease, transform .28s ease; }
        .feature-more-content .feature-item { margin-top: .45rem; }
        .feature-more.is-open .feature-more-content { max-height: 240px; opacity: 1; transform: translateY(0); }
        .feature-more-toggle { margin-top: .45rem; padding: 0; border: 0; background: transparent; color: rgba(196, 181, 253, .95); font-size: .86rem; font-weight: 600; cursor: pointer; }
        .feature-more-toggle:hover { color: #ddd6fe; }
        .card-footer { padding: 1.2rem 1.25rem 1.35rem; text-align: center; border-top: 1px solid rgba(255,255,255,.1); flex-shrink: 0; margin-top: auto; }
        .pricing-cta { display: inline-flex; align-items: center; gap: .75rem; padding: 1rem 2rem; background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); color: white; text-decoration: none; border-radius: 50px; font-weight: 600; transition: all .3s ease; width: 100%; justify-content: center; }
        .pricing-cta:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(79, 70, 229, .4); color: white; }
        .guarantee { margin-top: 1rem; color: rgba(255,255,255,.7); font-size: .9rem; }
        .enterprise-section { margin-top: 2.25rem; }
        .enterprise-content { background: rgba(255,255,255,.05); border-radius: 20px; padding: 3rem; backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,.1); display: flex; align-items: center; gap: 2rem; transition: all .3s ease; }
        .enterprise-content:hover { background: rgba(255,255,255,.08); transform: translateY(-5px); box-shadow: 0 20px 40px rgba(0,0,0,.2); }
        .enterprise-icon { width: 80px; height: 80px; background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); border-radius: 20px; display:flex; align-items:center; justify-content:center; font-size: 2rem; color: white; flex-shrink:0; }
        .enterprise-cta { display:inline-flex; align-items:center; gap:.75rem; padding:1rem 2rem; background: rgba(255,255,255,.1); color:white; text-decoration:none; border-radius:50px; font-weight:600; transition: all .3s ease; border:1px solid rgba(255,255,255,.2); }
        .enterprise-cta:hover { background: rgba(255,255,255,.15); transform: translateY(-3px); color:white; border-color: rgba(255,255,255,.3); }

        /* Contact */
        .contact-section {
            position: relative;
            background: linear-gradient(135deg, #0f0f23 0%, #1a1a3e 50%, #2d1b69 100%);
            color: white;
            overflow: hidden;
        }
        .contact-section::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 20% 30%, rgba(79,70,229,.12) 0%, transparent 45%),
                        radial-gradient(circle at 80% 70%, rgba(236,72,153,.1) 0%, transparent 45%);
            pointer-events: none;
        }
        .contact-section .container { position: relative; z-index: 1; }
        .contact-info-panel { height: 100%; }
        .contact-info-card {
            display: flex;
            align-items: flex-start;
            gap: 1.25rem;
            padding: 1.35rem 1.5rem;
            margin-bottom: 1rem;
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 16px;
            backdrop-filter: blur(10px);
            transition: all .3s ease;
        }
        .contact-info-card:hover {
            background: rgba(255,255,255,.08);
            transform: translateX(6px);
            border-color: rgba(79,70,229,.35);
        }
        .contact-info-icon {
            width: 52px;
            height: 52px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            font-size: 1.25rem;
            color: white;
            box-shadow: 0 8px 20px rgba(79,70,229,.25);
        }
        .contact-info-card h5 { margin: 0 0 .35rem; font-size: 1.05rem; font-weight: 600; color: white; }
        .contact-info-card p { margin: 0; color: rgba(255,255,255,.72); font-size: .95rem; line-height: 1.5; }
        .contact-info-card a { color: #c4b5fd; text-decoration: none; transition: color .2s; }
        .contact-info-card a:hover { color: white; }
        .contact-trust-badges {
            display: flex;
            flex-wrap: wrap;
            gap: .65rem;
            margin-top: 1.5rem;
        }
        .contact-trust-badge {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .4rem .85rem;
            border-radius: 999px;
            font-size: .82rem;
            font-weight: 600;
            color: rgba(255,255,255,.9);
            background: rgba(16,185,129,.12);
            border: 1px solid rgba(52,211,153,.35);
        }
        .contact-trust-badge i { color: #34d399; font-size: .75rem; }
        .contact-form-card {
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.12);
            border-radius: 24px;
            padding: 1.25rem 2.25rem;
            backdrop-filter: blur(16px);
            box-shadow: 0 24px 48px rgba(0,0,0,.25);
        }
        .contact-form-card h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: .35rem;
            background: linear-gradient(135deg, #fff 0%, #c4b5fd 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .contact-form-card .form-lead {
            color: rgba(255,255,255,.65);
            font-size: .95rem;
            margin-bottom: 1rem;
        }
        .contact-form .form-label {
            color: rgba(255,255,255,.85);
            font-size: .875rem;
            font-weight: 500;
            margin-bottom: .4rem;
        }
        .contact-form .form-label .required { color: #f472b6; margin-left: 2px; }
        .contact-form .input-group-text {
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.15);
            border-right: none;
            color: rgba(255,255,255,.55);
        }
        .contact-form .form-control,
        .contact-form .form-select {
            background: rgba(15,15,35,.55);
            border: 1px solid rgba(255,255,255,.15);
            color: white;
            border-radius: 12px;
            padding: .75rem 1rem;
            transition: border-color .2s, box-shadow .2s, background .2s;
        }
        .contact-form .input-group .form-control,
        .contact-form .input-group .form-select {
            border-left: none;
            border-top-left-radius: 0;
            border-bottom-left-radius: 0;
        }
        .contact-form .input-group .input-group-text {
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
        }
        .contact-form .form-control::placeholder { color: rgba(255,255,255,.35); }
        .contact-form .form-control:focus,
        .contact-form .form-select:focus {
            background: rgba(15,15,35,.75);
            border-color: rgba(124,58,237,.65);
            color: white;
            box-shadow: 0 0 0 3px rgba(79,70,229,.2);
        }
        .contact-form .form-select option { background: #1a1a3e; color: white; }
        .contact-form .form-control.is-invalid,
        .contact-form .form-select.is-invalid { border-color: #f87171; }
        .contact-form .invalid-feedback { color: #fca5a5; font-size: .82rem; }
        .contact-form textarea.form-control { min-height: 130px; resize: vertical; }
        .contact-form .form-check-label {
            color: rgba(255,255,255,.75);
            font-size: .875rem;
        }
        .contact-form .form-check-label a { color: #c4b5fd; }
        .contact-form .form-check-input {
            background-color: rgba(255,255,255,.08);
            border-color: rgba(255,255,255,.25);
        }
        .contact-form .form-check-input:checked {
            background-color: #7c3aed;
            border-color: #7c3aed;
        }
        .contact-consent-block {
            padding: 0.5rem 1.35rem;
            border-radius: 14px;
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.1);
        }
        .contact-consent-intro {
            color: rgba(255,255,255,.78);
            font-size: .875rem;
            line-height: 1.65;
            margin-bottom: 1.1rem;
        }
        .contact-consent-subhead {
            color: rgba(255,255,255,.9);
            font-size: .82rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin: 1.25rem 0 .75rem;
        }
        .contact-form .form-check + .form-check {
            margin-top: .85rem;
        }
        .contact-sms-disclaimer {
            color: rgba(255,255,255,.58);
            font-size: .78rem;
            line-height: 1.55;
            margin: .5rem 0 0 1.5rem;
        }
        .contact-sms-disclaimer a {
            color: #c4b5fd;
            text-decoration: underline;
        }
        .contact-submit-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .65rem;
            width: 100%;
            padding: 1rem 2rem;
            border: none;
            border-radius: 50px;
            font-size: 1.05rem;
            font-weight: 600;
            color: white;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            transition: all .3s ease;
            box-shadow: 0 10px 30px rgba(79,70,229,.35);
        }
        .contact-submit-btn:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 14px 36px rgba(79,70,229,.45);
            color: white;
        }
        .contact-submit-btn:disabled { opacity: .7; cursor: not-allowed; }
        .contact-alert {
            border-radius: 14px;
            border: none;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: .75rem;
        }
        .contact-alert-success {
            background: rgba(16,185,129,.15);
            color: #a7f3d0;
            border: 1px solid rgba(52,211,153,.35);
        }
        .contact-alert-error {
            background: rgba(239,68,68,.12);
            color: #fecaca;
            border: 1px solid rgba(248,113,113,.35);
        }
        @media (max-width: 991.98px) {
            .contact-form-card { padding: 1.75rem 1.25rem; }
            .contact-info-panel { margin-bottom: 2rem; }
        }

         /* ROI Calculator Button */
          .roi-calculator-btn {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 50px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 8px 25px rgba(255, 107, 107, 0.3);
            position: relative;
            overflow: hidden;
          }

          .roi-calculator-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: left 0.5s;
          }

          .roi-calculator-btn:hover::before {
            left: 100%;
          }

          .roi-calculator-btn:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 12px 35px rgba(255, 107, 107, 0.4);
          }

          .roi-calculator-btn i {
            font-size: 18px;
          }

          /* ROI Modal Styles */
          .roi-modal .modal-content {
            background: #2c2c54;
            border: none;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
            position: relative;
          }

          .roi-modal .modal-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.05)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.05)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.03)"/><circle cx="10" cy="60" r="0.5" fill="rgba(255,255,255,0.03)"/><circle cx="90" cy="40" r="0.5" fill="rgba(255,255,255,0.03)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            pointer-events: none;
          }

          .roi-modal .modal-header {
            background: rgba(255, 255, 255, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding: 1.5rem 2rem;
            position: relative;
            z-index: 1;
          }

          .roi-modal .modal-title {
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
          }

          .roi-modal .modal-title i {
            color: #ff6b6b;
            font-size: 1.8rem;
          }

          .roi-modal .btn-close {
            filter: invert(1);
            opacity: 0.8;
          }

          .roi-modal .btn-close:hover {
            opacity: 1;
          }

          .roi-modal .modal-body {
            padding: 2rem;
            position: relative;
            z-index: 1;
          }

          /* Calculator Container */
          .roi-calculator-container {
            color: white;
          }

          .calculator-intro {
            text-align: center;
            margin-bottom: 2rem;
          }

          .intro-text {
            font-size: 1.1rem;
            color: rgba(255, 255, 255, 0.9);
            line-height: 1.6;
            margin: 0;
          }

          /* Calculator Wrapper */
          .calculator-wrapper {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
          }

          .calculator-body {
            background: linear-gradient(145deg, #2c2c54, #1a1a2e);
            border-radius: 20px;
            padding: 20px;
            box-shadow: 
              0 20px 40px rgba(0, 0, 0, 0.3),
              inset 0 1px 0 rgba(255, 255, 255, 0.1);
            border: 2px solid rgba(255, 255, 255, 0.1);
            max-width: 400px;
            width: 100%;
          }

          /* Calculator Display */
          .calculator-display {
            background: #000;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            border: 3px solid #333;
            box-shadow: inset 0 2px 10px rgba(0, 0, 0, 0.5);
          }

          .display-screen {
            background: linear-gradient(135deg, #0a0a0a, #1a1a1a);
            border-radius: 10px;
            padding: 15px;
            min-height: 80px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            border: 1px solid #333;
          }

          .display-line {
            color: #00ff00;
            font-family: 'Courier New', monospace;
            font-size: 1.1rem;
            line-height: 1.4;
            text-align: right;
            margin: 2px 0;
            min-height: 20px;
            text-align: center;
          }

          .display-line:first-child {
            color: #ffff00;
            font-size: 0.9rem;
            text-align: center;
          }

          .display-line:last-child {
            color: #00ff00;
            font-size: 1.3rem;
            font-weight: bold;
          }

          /* Calculator Buttons */
          .calculator-buttons {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
          }

          .calc-btn {
            background: linear-gradient(145deg, #3a3a3a, #2a2a2a);
            border: 2px solid #444;
            border-radius: 12px;
            color: white;
            font-size: 1.1rem;
            font-weight: bold;
            padding: 15px 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 
              0 4px 8px rgba(0, 0, 0, 0.3),
              inset 0 1px 0 rgba(255, 255, 255, 0.1);
            user-select: none;
            min-height: 50px;
            align-items: center;
            justify-content: center;
          }

          .calc-btn:hover {
            background: linear-gradient(145deg, #4a4a4a, #3a3a3a);
            transform: translateY(-2px);
            box-shadow: 
              0 6px 12px rgba(0, 0, 0, 0.4),
              inset 0 1px 0 rgba(255, 255, 255, 0.2);
          }

          .calc-btn:active {
            transform: translateY(0);
            box-shadow: 
              0 2px 4px rgba(0, 0, 0, 0.3),
              inset 0 1px 0 rgba(255, 255, 255, 0.1);
          }

          .number-btn {
            background: linear-gradient(145deg, #4a4a4a, #3a3a3a);
            color: #fff;
          }

          .number-btn:hover {
            background: linear-gradient(145deg, #5a5a5a, #4a4a4a);
          }

          .function-btn {
            background: linear-gradient(145deg, #ff6b6b, #ee5a24);
            color: white;
            font-size: 0.9rem;
          }

          /* Active selected field button */
          .function-btn.active-field {
            outline: 2px solid #fff;
            box-shadow: 0 0 0 3px rgba(255,255,255,0.15), 0 8px 16px rgba(0,0,0,0.4);
            filter: brightness(1.05);
          }

          .function-btn:hover {
            background: linear-gradient(145deg, #ff7b7b, #ff6b34);
          }

          .operation-btn {
            background: linear-gradient(145deg, #4ecdc4, #44a08d);
            color: white;
            font-size: 1.3rem;
          }

          .operation-btn:hover {
            background: linear-gradient(145deg, #5eddd4, #54b09d);
          }

          .zero-btn {
            grid-column: span 2;
          }

          /* Responsive Calculator Styles */
          @media (max-width: 768px) {
            .calculator-body {
              max-width: 350px;
              padding: 15px;
            }

            .calculator-display {
              padding: 15px;
              margin-bottom: 15px;
            }

            .display-screen {
              padding: 12px;
              min-height: 70px;
            }

            .display-line {
              font-size: 1rem;
            }

            .display-line:last-child {
              font-size: 1.2rem;
            }

            .calc-btn {
              padding: 12px 6px;
              font-size: 1rem;
              min-height: 45px;
            }

            .function-btn {
              font-size: 0.8rem;
            }

            .operation-btn {
              font-size: 1.2rem;
            }
          }

          @media (max-width: 480px) {
            .calculator-body {
              max-width: 300px;
              padding: 12px;
            }

            .calculator-buttons {
              gap: 8px;
            }

            .calc-btn {
              padding: 10px 4px;
              font-size: 0.9rem;
              min-height: 40px;
            }

            .function-btn {
              font-size: 0.7rem;
            }
          }


          /* Results Section */
          .calculation-results {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 15px;
            padding: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            animation: slideInUp 0.5s ease-out;
          }

          @keyframes slideInUp {
            from {
              opacity: 0;
              transform: translateY(30px);
            }
            to {
              opacity: 1;
              transform: translateY(0);
            }
          }

          .results-header {
            text-align: center;
            margin-bottom: 2rem;
          }

          .results-header h4 {
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin: 0;
          }

          .results-header i {
            color: #ff6b6b;
            font-size: 1.8rem;
          }

          .results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
          }

          .result-card {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            padding: 1.5rem;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
          }

          .result-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #ff6b6b, #ee5a24);
          }

          .result-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
          }

          .result-icon {
            margin-bottom: 1rem;
          }

          .result-icon i {
            font-size: 2.5rem;
            color: #ff6b6b;
          }

          .result-content h5 {
            color: white;
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
          }

          .result-amount {
            font-size: 2rem;
            font-weight: 700;
            color: #ff6b6b;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
          }

          /* Impact Message */
          .impact-message {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.1) 0%, rgba(238, 90, 36, 0.1) 100%);
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 107, 107, 0.3);
            display: flex;
            align-items: center;
            gap: 1rem;
          }

          .impact-icon i {
            font-size: 2rem;
            color: #ff6b6b;
          }

          .impact-text h5 {
            color: white;
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
          }

          .impact-text p {
            color: rgba(255, 255, 255, 0.9);
            margin: 0;
            line-height: 1.5;
          }

          /* CTA Section */
          .cta-section {
            text-align: center;
          }

          .cta-section .btn {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            border: none;
            padding: 15px 30px;
            border-radius: 50px;
            font-size: 1.1rem;
            font-weight: 600;
            color: white;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 8px 25px rgba(255, 107, 107, 0.3);
          }

          .cta-section .btn:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 12px 35px rgba(255, 107, 107, 0.4);
            color: white;
          }

          /* Responsive Design */
          @media (max-width: 768px) {
            .cta-buttons {
              flex-direction: column;
              gap: 1rem;
            }

            .roi-calculator-btn {
              width: 100%;
              justify-content: center;
            }

            .roi-modal .modal-dialog {
              margin: 1rem;
            }

            .roi-modal .modal-body {
              padding: 1.5rem;
            }

            .results-grid {
              grid-template-columns: 1fr;
            }

            .impact-message {
              flex-direction: column;
              text-align: center;
            }

            .result-amount {
              font-size: 1.5rem;
            }
          }

          @media (max-width: 576px) {
            .roi-modal .modal-header {
              padding: 1rem 1.5rem;
            }

            .roi-modal .modal-body {
              padding: 1rem;
            }

            .roi-modal .modal-title {
              font-size: 1.2rem;
            }

            .calculation-results {
              padding: 1.5rem;
            }

            .result-card {
              padding: 1rem;
            }
          }

          /* Animation for number counting */
          .counting {
            animation: countUp 1s ease-out;
          }

          @keyframes countUp {
            from {
              opacity: 0;
              transform: scale(0.5);
            }
            to {
              opacity: 1;
              transform: scale(1);
            }
          }

          /* Floating ROI Button */
          .floating-roi-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
          }

          .btn-floating {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 15px 20px;
            box-shadow: 0 8px 25px rgba(255, 107, 107, 0.4);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 600;
            animation: pulse 2s infinite;
          }

          .btn-floating:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 12px 35px rgba(255, 107, 107, 0.5);
            color: white;
          }

          .btn-floating i {
            font-size: 18px;
          }

          .floating-text {
            display: none;
          }

          @keyframes pulse {
            0% {
              box-shadow: 0 8px 25px rgba(255, 107, 107, 0.4);
            }
            50% {
              box-shadow: 0 8px 25px rgba(255, 107, 107, 0.6), 0 0 0 10px rgba(255, 107, 107, 0.1);
            }
            100% {
              box-shadow: 0 8px 25px rgba(255, 107, 107, 0.4);
            }
          }

          /* Show text on larger mobile screens */
          @media (min-width: 480px) {
            .floating-text {
              display: inline;
            }
          }
    </style>
@endsection

@section('content')
  <!-- Hero Section -->
  <div class="hero-container py-120">
    <div class="floating-shapes">
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
    </div>

    <div class="main-content">
        <div class="left-content">
            <h1 class="hero-title">Every Missed Call Is a Missed Opportunity</h1>
            <p class="hero-subtitle"><span style="font-weight: bold;">With 24/7 live human agents, SureHelp Solution ensures you never miss a lead, a booking, or a client.</span><br>We answer your business calls day and night - capturing leads, handling inquiries, and booking appointments. So you can focus on running your business.</p>

            <div class="stats-grid">
                <div class="stat-card" data-stat="95">
                    <i class="fas fa-phone-volume stat-icon"></i>
                    <span class="stat-number">0%</span>
                    <div class="stat-label">We answer 95%+ of calls live, so your customers never go unanswered</div>
                </div>
                <div class="stat-card" data-stat="60">
                    <i class="fas fa-piggy-bank stat-icon"></i>
                    <span class="stat-number">0%</span>
                    <div class="stat-label">Save up to 60% compared to hiring full-time in-house staff</div>
                </div>
                <div class="stat-card" data-stat="30">
                    <i class="fas fa-chart-line stat-icon"></i>
                    <span class="stat-number">0%</span>
                    <div class="stat-label">Our clients report booking 30% more appointments in month on</div>
                </div>
            </div>

            <div class="cta-buttons">
                <!--<a href="#" class="btn btn-primary">-->
                <!--    Start Free Trial-->
                <!--    <i class="fas fa-arrow-right"></i>-->
                <!--</a>-->
                <a href="/#contact" class="btn btn-secondary">
                    Book a Demo
                    <i class="fas fa-play"></i>
                </a>
            </div>

            <div class="trust-badge">
                <i class="fas fa-shield-alt"></i>
                No setup fees • Cancel anytime • 30-day money-back guarantee
            </div>
            <div class="client-section">
              <i class="fas fa-users client-icon" aria-hidden="true"></i>
              <p class="client-text">Our clients report an average of 30% more booked appointments in the first 30 days</p>
            </div>
            
            <!-- <div class="client-section">
                <p class="client-text">Trusted by 500+ growing businesses</p>
                <div class="client-logos">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/9/96/Microsoft_logo_%282012%29.svg" alt="Microsoft"
                        style="height: 23px;">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/5/51/IBM_logo.svg" alt="IBM" style="height: 25px;">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/2/2f/Google_2015_logo.svg" alt="Google"
                        style="height: 30px;">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/0/08/Netflix_2015_logo.svg" alt="Netflix"
                        style="height: 35px;">
                    <img src="https://upload.wikimedia.org/wikipedia/commons/f/fa/Apple_logo_black.svg" alt="Apple"
                        style="height: 35px;">
                </div>
            </div> -->
        </div>

        <div class="right-content" style="margin-top: -135px;">
            <div class="visual-container">
                <div class="phone-mockup" style="display: none;">
                    <div class="phone-screen">
                        <div class="screen-content">
                            <div class="app-header">
                                <div class="app-title">Sure Help</div>
                                <div class="app-subtitle">Smart Call Management</div>
                            </div>

                            <div class="call-visualization">
                                <div class="call-ring">
                                    <img src="assets/img/agent.png" style="width: 100%;">
                                </div>
                                <!-- <div class="call-ring">
                                    <i class="fas fa-phone call-icon"></i>
                                </div> -->
                                <div class="incoming-call">
                                    <div class="caller-name">New Customer</div>
                                    <div class="caller-number">+1 (858) 321 3947</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="floating-cards">
                    <div class="floating-card">
                        <i class="fas fa-chart-bar"></i>
                        <div>95% Capture Rate</div>
                    </div>
                    <div class="floating-card">
                        <i class="fas fa-clock"></i>
                        <div>24/7 Support</div>
                    </div>
                    <div class="floating-card">
                        <i class="fas fa-shield-alt"></i>
                        <div>Secure & Reliable</div>
                    </div>
                </div>
                <img src="assets/img/agent.png" style="width: 100%;">
            </div>
        </div>
    </div>
</div>

  <!-- Features Section -->
  <section class="features-section">
        <div class="container">
            <div class="section-header">
      <h2 class="section-title">Everything You Need to Never Miss a Call</h2>
      <p class="section-subtitle">Our comprehensive platform combines cutting-edge technology with human expertise to deliver unparalleled call management services.</p>
            </div>

            <div class="features-grid">
      <!-- Feature 1 -->
      <div class="feature-card">
                    <div class="feature-icon-container">
                        <div class="feature-icon">
                            <i class="fas fa-phone-alt"></i>
                        </div>
                    </div>
        <h3 class="feature-title">24/7 Call Answering</h3>
        <p class="feature-description">Professional human agents available around the clock to handle your calls with care and expertise.</p>
                    <ul class="feature-list">
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">Live human receptionists</span>
                        </li>
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">Custom call scripts</span>
                        </li>
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">Instant message delivery</span>
                        </li>
                    </ul>
        <a href="{{ route('pages.show', 'call-answering') }}" class="demo-button">Learn More</a>
                </div>

      <!-- Feature 2 -->
      <div class="feature-card">
                    <div class="feature-icon-container">
                        <div class="feature-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                    </div>
        <h3 class="feature-title">Smart Scheduling</h3>
        <p class="feature-description">Seamlessly book appointments and manage your calendar with intelligent scheduling tools.</p>
                    <ul class="feature-list">
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">Calendar integration</span>
                        </li>
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">Automated reminders</span>
                        </li>
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">Time zone handling</span>
                        </li>
                    </ul>
        <a href="{{ route('pages.show', 'appointment-booking') }}" class="demo-button">Learn More</a>
                </div>

      <!-- Feature 3 -->
      <div class="feature-card">
                    <div class="feature-icon-container">
                        <div class="feature-icon">
            <i class="fas fa-users"></i>
                        </div>
                    </div>
        <h3 class="feature-title">Customer Management</h3>
        <p class="feature-description">Complete CRM solution to track leads, manage customer relationships, and grow your business.</p>
                    <ul class="feature-list">
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">Lead tracking</span>
                        </li>
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">Customer profiles</span>
                        </li>
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">Follow-up automation</span>
                        </li>
                    </ul>
        <a href="{{ route('pages.show', 'customer-management') }}" class="demo-button">Learn More</a>
                </div>

      <!-- Feature 4 -->
      <div class="feature-card">
                    <div class="feature-icon-container">
                        <div class="feature-icon">
            <i class="fas fa-chart-line"></i>
                        </div>
                    </div>
        <h3 class="feature-title">Analytics Dashboard</h3>
        <p class="feature-description">Comprehensive insights into your call performance, customer interactions, and business growth.</p>
                    <ul class="feature-list">
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">Real-time metrics</span>
                        </li>
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">Performance reports</span>
                        </li>
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">ROI tracking</span>
                        </li>
                    </ul>
        <a href="{{ route('pages.show', 'analytics-dashboard') }}" class="demo-button">Learn More</a>
                </div>

      <!-- Feature 5 -->
      <div class="feature-card">
                    <div class="feature-icon-container">
                        <div class="feature-icon">
            <i class="fas fa-plug"></i>
                        </div>
                    </div>
        <h3 class="feature-title">Easy Integration</h3>
        <p class="feature-description">Connect with your existing tools and workflows through our extensive integration marketplace.</p>
                    <ul class="feature-list">
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">Popular CRM systems</span>
                        </li>
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">Calendar platforms</span>
                        </li>
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">API access</span>
                        </li>
                    </ul>
        <a href="{{ route('pages.show', 'integrations') }}" class="demo-button">Learn More</a>
                </div>

      <!-- Feature 6 -->
      <div class="feature-card">
                    <div class="feature-icon-container">
                        <div class="feature-icon">
            <i class="fas fa-shield-alt"></i>
                        </div>
                    </div>
        <h3 class="feature-title">Enterprise Security</h3>
        <p class="feature-description">Bank-level security and compliance to protect your data and maintain customer trust.</p>
                    <ul class="feature-list">
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">SOC 2 compliance</span>
                        </li>
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">End-to-end encryption</span>
                        </li>
                        <li class="feature-list-item">
                            <div class="check-icon">
                                <i class="fas fa-check"></i>
                            </div>
            <span class="feature-list-text">Regular audits</span>
                        </li>
                    </ul>
        <a href="{{ route('legal.data-security') }}" class="demo-button">Learn More</a>
                </div>
            </div>

    <!-- Interactive Showcase -->
            <div class="interactive-showcase">
      <h3 style="color: white; font-size: 2rem; margin-bottom: 1rem;">Trusted by Growing Businesses</h3>
      <p style="color: rgba(255, 255, 255, 0.7); margin-bottom: 2rem;">Join thousands of businesses that have transformed their customer communication</p>
      
                <div class="showcase-grid">
        <div class="showcase-item">
          <div class="showcase-number">500+</div>
          <div class="showcase-label">Active Clients</div>
                    </div>
        <div class="showcase-item">
          <div class="showcase-number">95%</div>
          <div class="showcase-label">Call Capture Rate</div>
                    </div>
        <div class="showcase-item">
          <div class="showcase-number">24/7</div>
          <div class="showcase-label">Availability</div>
                    </div>
        <div class="showcase-item">
                        <div class="showcase-number">99.9%</div>
          <div class="showcase-label">Uptime</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

  <!-- How It Works Section -->
  <section class="how-it-works-section py-120">
    <div class="container">
        <div class="section-header text-center mb-5">
            <h2 class="section-title">Go Live in Just 2 Days. It's That Simple</h2>
            <p class="section-subtitle">From setup to real-time call handling, we'll get your business up and running in 48 hours</p>
        </div>

        <div class="timeline-container">
            <div class="timeline-step">
                <div class="timeline-icon">
                    <div class="icon-circle"><i class="fas fa-cog"></i></div>
                    <div class="step-number">1</div>
                </div>
                <div class="timeline-content">
                    <h3>Setup & Script <span class="text-gradient">(Day 1)</span></h3>
                        <ul class="timeline-list">
                        <li><span><i class="fas fa-check-circle"></i>&nbsp;&nbsp;Configure call forwarding system</span></li>
                        <li><span><i class="fas fa-check-circle"></i>&nbsp;&nbsp;Create custom call scripts</span></li>
                        <li><span><i class="fas fa-check-circle"></i>&nbsp;&nbsp;Train agents on your brand voice</span></li>
                        <li><span><i class="fas fa-check-circle"></i>&nbsp;&nbsp;Run test calls for quality assurance</span></li>
                        </ul>
                </div>
            </div>

            <div class="timeline-step">
                <div class="timeline-icon">
                    <div class="icon-circle"><i class="fas fa-rocket"></i></div>
                    <div class="step-number">2</div>
                </div>
                <div class="timeline-content">
                    <h3>Go Live <span class="text-gradient">(Day 2)</span></h3>
                        <ul class="timeline-list">
                        <li><span><i class="fas fa-check-circle"></i>&nbsp;&nbsp;Begin handling real customer calls</span></li>
                        <li><span><i class="fas fa-check-circle"></i>&nbsp;&nbsp;Monitor call quality in real-time</span></li>
                        <li><span><i class="fas fa-check-circle"></i>&nbsp;&nbsp;Track performance metrics</span></li>
                        <li><span><i class="fas fa-check-circle"></i>&nbsp;&nbsp;Receive detailed call reports</span></li>
                        </ul>
                </div>
            </div>

            <div class="timeline-step">
                <div class="timeline-icon">
                    <div class="icon-circle"><i class="fas fa-chart-line"></i></div>
                    <div class="step-number">3</div>
                </div>
                <div class="timeline-content">
                    <h3>Optimize & Scale <span class="text-gradient">(Ongoing)</span></h3>
                        <ul class="timeline-list">
                        <li><i class="fas fa-check-circle"></i><span>&nbsp;&nbsp;Refine scripts based on feedback</span></li>
                        <li><i class="fas fa-check-circle"></i><span>&nbsp;&nbsp;Scale service as you grow</span></li>
                        <li><i class="fas fa-check-circle"></i><span>&nbsp;&nbsp;Regular performance reviews</span></li>
                        <li><i class="fas fa-check-circle"></i><span>&nbsp;&nbsp;Continuous agent training</span></li>
                        </ul>
                </div>
            </div>
        </div>

        <div class="cta-container text-center mt-5">
            <h3 class="cta-title">Ready to launch your business support in 48 hours?</h3>
            <div class="cta-buttons" style="justify-content: center; align-items: center; gap: 1rem;">
                <a href="#contact" class="cta-button"><span>Get Started Today</span><i class="fas fa-arrow-right"></i></a>
                <button type="button" class="roi-calculator-btn" data-bs-toggle="modal" data-bs-target="#roiCalculatorModal">
                    <i class="fas fa-calculator"></i>
                    <span>Calculate Your Loss</span>
                </button>
            </div>
        </div>
    </div>
</section>

  <!-- Testimonials Section -->
  <section class="testimonials-section py-120">
    <div class="container">
        <div class="section-header text-center mb-5">
            <h2 class="section-title">A New Team With Something to Prove</h2>
            <p class="section-subtitle">We are a brand-new company, and we are hungry to earn your trust with real service, fast support, and zero fluff.</p>
        </div>

        <div class="testimonial-cta">
            <h3>We're new and hungry to prove ourselves.</h3>
            <p class="new-company-note">That is exactly why we offer a risk-free first month. Try us in real business conditions and keep going only if you see the value.</p>
            <div class="new-company-grid">
                <div class="new-company-item"><i class="fas fa-shield-alt"></i><span>Risk-free first month</span></div>
                <div class="new-company-item"><i class="fas fa-headset"></i><span>Real human answering from day one</span></div>
                <div class="new-company-item"><i class="fas fa-clock"></i><span>Fast setup and responsive support</span></div>
                <div class="new-company-item"><i class="fas fa-handshake"></i><span>No long-term lock-in</span></div>
            </div>
            <div class="cta-buttons">
                <a href="#contact" class="cta-button primary"><span>Start Your Free Setup</span><i class="fas fa-arrow-right"></i></a>
                <span class="cta-divider">or</span>
                <a href="#contact" class="cta-button secondary"><span>Book a Demo</span><i class="fas fa-calendar-alt"></i></a>
            </div>
        </div>
    </div>
</section>

  <!-- <section class="testimonials-section py-120">
    <div class="container">
        <div class="section-header text-center mb-5">
            <h2 class="section-title">From Missed Calls to More Clients, See the Difference</h2>
            <p class="section-subtitle">From solo professionals to growing teams, discover how SureHelp helps businesses capture more leads, serve more clients, and grow faster</p>
        </div>

        <div class="testimonials-grid">
            <div class="testimonial-card">
                <div class="testimonial-header">
                    <div class="client-info">
                        <div class="client-image"><img src="https://images.pexels.com/photos/8961065/pexels-photo-8961065.jpeg?auto=compress&cs=tinysrgb&w=100" alt="Mike Johnson"></div>
                        <div class="client-details"><h4>Mike Johnson</h4><p>Mike's Plumbing - Los Angeles, CA</p></div>
                        </div>
                    <div class="rating"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i></div>
                        </div>
                <div class="testimonial-content"><p>"SureHelp completely transformed my business. I never miss emergency calls anymore, and my customers love the professional service. It's like having a reception team at a fraction of the cost."</p></div>
                <div class="testimonial-results">
                    <div class="result-badge"><i class="fas fa-chart-line"></i><span>95% Call Capture Rate</span></div>
                    <div class="result-badge"><i class="fas fa-dollar-sign"></i><span>40% Revenue Increase</span></div>
                </div>
            </div>

            <div class="testimonial-card">
                <div class="testimonial-header">
                    <div class="client-info">
                        <div class="client-image"><img src="https://images.pexels.com/photos/3992874/pexels-photo-3992874.jpeg?auto=compress&cs=tinysrgb&w=100" alt="Isabella Martinez"></div>
                        <div class="client-details"><h4>Isabella Martinez</h4><p>Bella's Hair Studio - Miami, FL</p></div>
                        </div>
                    <div class="rating"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i></div>
                        </div>
                <div class="testimonial-content"><p>"My stylists can now focus completely on clients while SureHelp handles all the calls. Our schedule is always full, and clients love the professional booking experience."</p></div>
                <div class="testimonial-results">
                    <div class="result-badge"><i class="fas fa-calendar-check"></i><span>30% More Bookings</span></div>
                    <div class="result-badge"><i class="fas fa-user-check"></i><span>50% Less No-Shows</span></div>
                </div>
            </div>

            <div class="testimonial-card">
                <div class="testimonial-header">
                    <div class="client-info">
                        <div class="client-image"><img src="https://images.pexels.com/photos/5327585/pexels-photo-5327585.jpeg?auto=compress&cs=tinysrgb&w=100" alt="Dr. Sarah Chen"></div>
                        <div class="client-details"><h4>Dr. Sarah Chen</h4><p>Downtown Dental - Chicago, IL</p></div>
                        </div>
                    <div class="rating"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i></div>
                        </div>
                <div class="testimonial-content"><p>"Patients love the professional service and our practice runs so much smoother. We've been able to see 20% more patients without adding staff. It's really good for me."</p></div>
                <div class="testimonial-results">
                    <div class="result-badge"><i class="fas fa-clock"></i><span>25% Less No-Shows</span></div>
                    <div class="result-badge"><i class="fas fa-smile"></i><span>45% Patient Satisfaction ↑</span></div>
                </div>
            </div>
        </div>

        <div class="testimonial-cta">
            <h3>Want results like these?</h3>
            <div class="cta-buttons">
                <a href="#contact" class="cta-button primary"><span>Start Your Free Setup</span><i class="fas fa-arrow-right"></i></a>
                <span class="cta-divider">or</span>
                <a href="#contact" class="cta-button secondary"><span>Book a Demo Now</span><i class="fas fa-calendar-alt"></i></a>
            </div>
        </div>
    </div>
</section> -->

  <!-- Pricing Section -->
  <section id="pricing" class="pricing-section py-120">
    <div class="container">
        <div class="section-header text-center mb-5">
            <h2 class="section-title">Affordable, Predictable, Built for Growth</h2>
            <div class="compliance-strip" aria-label="Security, compliance, and guarantees">
                <span class="compliance-pill">🔒 HIPAA Ready</span>
                <span class="compliance-pill">📋 BAA Included</span>
                <span class="compliance-pill">🛡️ DPA Compliant</span>
                <span class="compliance-pill">🔐 SOC 2</span>
                <span class="compliance-pill">🔑 End-to-End Encrypted</span>
                <span class="compliance-pill compliance-pill--guarantee">✅ 30-day money-back guarantee</span>
            </div>
            <div class="pricing-toggle">
                <div class="toggle-container">
                    <span class="toggle-option">Monthly</span>
                    <label class="switch">
                        <input type="checkbox" id="pricingToggle">
                        <span class="slider"></span>
                    </label>
                    <span class="toggle-option">Annual</span>
                    <span class="save-badge">Save 10%</span>
                </div>
            </div>
        </div>

        <div class="pricing-grid">
            <div class="pricing-card">
                <div class="card-header">
                    <div class="plan-name">Essential</div>
                    <div class="price-container">
                        <div class="price-monthly essential-monthly" style="display: flex;">
                            <span class="currency">$</span><span class="amount">300</span><span class="period">/month</span>
                        </div>
                        <div class="price-annual essential-annual d-none" style="display: none;">
                            <span class="currency">$</span><span class="amount">270</span><span class="period">/month</span>
                        </div>
                    </div>
                    <p class="plan-description">Best for solo professionals &amp; coaches</p>
                </div>
                <div class="feature-list">
                    <div class="feature-item"><i class="fas fa-check"></i><span><b>100 minutes/month included</b></span></div>
                    <div class="feature-item"><i class="fas fa-check"></i><span>24/7 call answering</span></div>
                    <div class="feature-item"><i class="fas fa-check"></i><span>Appointment scheduling</span></div>
                    <div class="feature-item"><i class="fas fa-check"></i><span>SMS/Email notifications</span></div>
                    <div class="feature-item"><i class="fas fa-check"></i><span>Client Portal & app access</span></div>
                    <div class="feature-more" data-feature-toggle>
                        <div class="feature-more-content">
                            <div class="feature-item"><i class="fas fa-check"></i><span>Basic follow-up on up to 50 calls/month</span></div>
                            <div class="feature-item"><i class="fas fa-check"></i><span>Message taking & forwarding</span></div>
                            <div class="feature-item"><i class="fas fa-check"></i><span>Extra calls: $2.00/call</span></div>
                        </div>
                        <button type="button" class="feature-more-toggle" aria-expanded="false">See more..</button>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="/#contact" class="pricing-cta"><span>Start Free Trial</span><i class="fas fa-arrow-right"></i></a>
                </div>
            </div>

            <div class="pricing-card">
                <div class="card-header">
                    <div class="plan-name">Professional</div>
                    <div class="price-container">
                        <div class="price-monthly professional-monthly" style="display: flex;">
                            <span class="currency">$</span><span class="amount">400</span><span class="period">/month</span>
                        </div>
                        <div class="price-annual professional-annual d-none" style="display: none;">
                            <span class="currency">$</span><span class="amount">360</span><span class="period">/month</span>
                        </div>
                    </div>
                    <p class="plan-description">Best for home services &amp; growing businesses</p>
                </div>
                <div class="feature-list">
                    <div class="feature-item"><i class="fas fa-check"></i><span><b>150 minutes/month included</b></span></div>
                    <div class="feature-item"><i class="fas fa-check"></i><span>Everything in Essential</span></div>
                    <div class="feature-item"><i class="fas fa-check"></i><span>Custom call scripts</span></div>
                    <div class="feature-item"><i class="fas fa-check"></i><span>Full follow-up on every call</span></div>
                    <div class="feature-item"><i class="fas fa-check"></i><span>Monthly performance reports</span></div>
                    <div class="feature-more" data-feature-toggle>
                        <div class="feature-more-content">
                            <div class="feature-item"><i class="fas fa-check"></i><span>Priority notifications</span></div>
                            <div class="feature-item"><i class="fas fa-check"></i><span>Extra calls: $1.50/call</span></div>
                            <div class="feature-item"><i class="fas fa-check"></i><span>Enhanced client portal</span></div>
                            <div class="feature-item"><i class="fas fa-check"></i><span>Advanced analytics dashboard</span></div>
                        </div>
                        <button type="button" class="feature-more-toggle" aria-expanded="false">See more..</button>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="/#contact" class="pricing-cta"><span>Start Free Trial</span><i class="fas fa-arrow-right"></i></a>
                </div>
            </div>

            <div class="pricing-card popular">
                <div class="popular-badge">⭐ MOST POPULAR</div>
                <div class="card-header">
                    <div class="plan-name">Growth</div>
                    <div class="price-container">
                        <div class="price-monthly premium-monthly" style="display: flex;">
                            <span class="currency">$</span><span class="amount">450</span><span class="period">/month</span>
                        </div>
                        <div class="price-annual premium-annual d-none" style="display: none;">
                            <span class="currency">$</span><span class="amount">405</span><span class="period">/month</span>
                        </div>
                    </div>
                    <p class="plan-description">Best for medical, legal &amp; financial practices</p>
                </div>
                <div class="feature-list">
                    <div class="feature-item"><i class="fas fa-check"></i><span><b>200 minutes/month included</b></span></div>
                    <div class="feature-item"><i class="fas fa-check"></i><span>Everything in Professional</span></div>
                    <div class="feature-item"><i class="fas fa-check"></i><span>Dedicated account manager</span></div>
                    <div class="feature-item"><i class="fas fa-check"></i><span>Advanced analytics & reporting</span></div>
                    <div class="feature-item"><i class="fas fa-check"></i><span>Priority support & white-label options</span></div>
                    <div class="feature-more" data-feature-toggle>
                        <div class="feature-more-content">
                            <div class="feature-item"><i class="fas fa-check"></i><span>2x call follow-up + priority routing</span></div>
                            <div class="feature-item"><i class="fas fa-check"></i><span>Custom call scripts</span></div>
                            <div class="feature-item"><i class="fas fa-check"></i><span>Extra calls: $1.25/call</span></div>
                        </div>
                        <button type="button" class="feature-more-toggle" aria-expanded="false">See more..</button>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="/#contact" class="pricing-cta"><span>Start Free Trial</span><i class="fas fa-arrow-right"></i></a>
                </div>
            </div>

            <div class="pricing-card pricing-card-enterprise">
                <div class="card-header">
                    <div class="plan-name">Enterprise</div>
                    <div class="price-container">
                        <div class="price-custom-display" style="display: flex;">
                            <span class="amount">Custom</span>
                        </div>
                    </div>
                    <p class="plan-description">Best for franchises &amp; multi-location businesses</p>
                </div>
                <div class="feature-list">
                    <div class="feature-item"><i class="fas fa-check"></i><span><b>Custom minutes</b></span></div>
                    <div class="feature-item"><i class="fas fa-check"></i><span>Everything in Growth</span></div>
                    <div class="feature-item"><i class="fas fa-check"></i><span>Dedicated franchise teams</span></div>
                    <div class="feature-item"><i class="fas fa-check"></i><span>Custom integrations & automation</span></div>
                    <div class="feature-item"><i class="fas fa-check"></i><span>White glove onboarding</span></div>
                    <div class="feature-more" data-feature-toggle>
                        <div class="feature-more-content">
                            <div class="feature-item"><i class="fas fa-check"></i><span>Guaranteed SLAs &amp; priority escalation</span></div>
                            <div class="feature-item"><i class="fas fa-check"></i><span>Volume pricing &amp; tailored contracts</span></div>
                        </div>
                        <button type="button" class="feature-more-toggle" aria-expanded="false">See more</button>
                    </div>
                </div>
                <div class="card-footer">
                    <a href="/#contact" class="pricing-cta"><span>Start Free Trial</span><i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
        </div>


        <!-- <div class="enterprise-section">
            <div class="enterprise-content">
                <div class="enterprise-icon"><i class="fas fa-building"></i></div>
                <div class="enterprise-info">
                    <h3>Enterprise Solutions</h3>
                    <p>Custom pricing for high volume / unlimited calls with tailored SLAs and specialized requirements</p>
                    <ul class="enterprise-features">
                        <li><i class="fas fa-check"></i> Tailored call volumes & SLAs</li>
                        <li><i class="fas fa-check"></i> Bilingual support</li>
                        <li><i class="fas fa-check"></i> Industry compliance (HIPAA, Legal, etc.)</li>
                        <li><i class="fas fa-check"></i> Dedicated team & custom integrations</li>
                    </ul>
                    <a href="#contact" class="enterprise-cta"><span>Contact Us for Custom Pricing</span><i class="fas fa-arrow-right"></i></a>
                </div>
            </div>
        </div> -->
    </div>
</section>

  @include('partials.contact-section')

  <!-- Floating ROI Calculator Button (Mobile) -->
  <div class="floating-roi-btn d-md-none">
    <button type="button" class="btn btn-floating" data-bs-toggle="modal" data-bs-target="#roiCalculatorModal">
      <i class="fas fa-calculator"></i>
      <span class="floating-text">Calculate Loss</span>
    </button>
  </div>

  <!-- ROI Calculator Modal -->
<div class="modal fade" id="roiCalculatorModal" tabindex="-1" aria-labelledby="roiCalculatorModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content roi-modal">
      <div class="login-modal-wrapper" style="padding: 0;">

        <!-- Modal Header -->
        <div class="modal-header">
          <h5 class="modal-title" id="roiCalculatorModalLabel">
            <i class="fas fa-calculator"></i> ROI Calculator - Calculate Your Lost Revenue
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>

        <!-- Modal Body -->
        <div class="modal-body">
          <div class="roi-calculator-container">

            <!-- Intro -->
            <div class="calculator-intro">
              <p class="intro-text">
                Every missed call is a missed opportunity. Calculate how much revenue you're losing
                when potential customers can't reach you.
              </p>
            </div>

            <!-- Calculator -->
            <div class="calculator-wrapper">
              <div class="calculator-body">

                <!-- Display -->
                <div class="calculator-display">
                  <div class="display-screen">
                    <div class="display-line" id="displayLine1">ROI Calculator</div>
                    <div class="display-line" id="displayLine2">Missed Calls Per Month</div>
                    <div class="display-line" id="displayLine3">0</div>
                  </div>
                </div>

                <!-- Buttons -->
                <div class="calculator-buttons">
                  <button class="calc-btn function-btn" onclick="clearCalculator()">C</button>
                  <button id="btnCalls" class="calc-btn function-btn" onclick="setField('missedCalls')">
                    Calls<br> <span id="btnCallsValue">0</span>
                  </button>
                  <button id="btnValue" class="calc-btn function-btn" onclick="setField('avgCallValue')">
                    Value<br> $<span id="btnValueAmount">200</span>
                  </button>
                  <button id="btnRate" class="calc-btn function-btn" onclick="setField('conversionRate')">
                    Rate<br> <span id="btnRatePercent">25</span>%
                  </button>

                  <!-- Row 1 -->
                  <button class="calc-btn number-btn" onclick="inputNumber('7')">7</button>
                  <button class="calc-btn number-btn" onclick="inputNumber('8')">8</button>
                  <button class="calc-btn number-btn" onclick="inputNumber('9')">9</button>
                  <button class="calc-btn operation-btn" onclick="calculateROI()">=</button>

                  <!-- Row 2 -->
                  <button class="calc-btn number-btn" onclick="inputNumber('4')">4</button>
                  <button class="calc-btn number-btn" onclick="inputNumber('5')">5</button>
                  <button class="calc-btn number-btn" onclick="inputNumber('6')">6</button>
                  <button class="calc-btn function-btn" onclick="backspace()">⌫</button>

                  <!-- Row 3 -->
                  <button class="calc-btn number-btn" onclick="inputNumber('1')">1</button>
                  <button class="calc-btn number-btn" onclick="inputNumber('2')">2</button>
                  <button class="calc-btn number-btn" onclick="inputNumber('3')">3</button>
                  <button class="calc-btn number-btn" onclick="inputNumber('.')">.</button>

                  <!-- Row 4 -->
                  <button class="calc-btn number-btn zero-btn" onclick="inputNumber('0')">0</button>
                  <button class="calc-btn function-btn" onclick="showResults()">Results</button>
                  <button class="calc-btn function-btn" onclick="resetCalculator()">Reset</button>
                </div>

                <!-- Hidden Inputs -->
                <input type="hidden" id="missedCalls" value="">
                <input type="hidden" id="avgCallValue" value="200">
                <input type="hidden" id="conversionRate" value="25">
                <input type="hidden" id="currentField" value="">

              </div>
            </div>

            <!-- Results -->
            <div class="calculation-results" id="calculationResults" style="display: none;">
              <div class="results-header">
                <h4><i class="fas fa-chart-line"></i> Your Revenue Loss Analysis</h4>
              </div>

              <div class="results-grid">
                <div class="result-card monthly-loss">
                  <div class="result-icon"><i class="fas fa-calendar-alt"></i></div>
                  <div class="result-content">
                    <h5>Monthly Loss</h5>
                    <div class="result-amount" id="monthlyLoss">$0</div>
                  </div>
                </div>

                <div class="result-card yearly-loss">
                  <div class="result-icon"><i class="fas fa-chart-bar"></i></div>
                  <div class="result-content">
                    <h5>Yearly Loss</h5>
                    <div class="result-amount" id="yearlyLoss">$0</div>
                  </div>
                </div>

                <div class="result-card potential-revenue">
                  <div class="result-icon"><i class="fas fa-trophy"></i></div>
                  <div class="result-content">
                    <h5>Potential Revenue</h5>
                    <div class="result-amount" id="potentialRevenue">$0</div>
                  </div>
                </div>
              </div>

              <!-- Impact Message -->
              <div class="impact-message">
                <div class="impact-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="impact-text">
                  <h5>This is money you could be earning!</h5>
                  <p>
                    With SureHelp Solution's 24/7 call answering service, you can capture every lead
                    and never miss another opportunity.
                  </p>
                </div>
              </div>

              <!-- CTA -->
              <div class="cta-section">
                <button type="button" class="btn btn-primary btn-lg" data-bs-dismiss="modal" onclick="scrollToContact()">
                  <i class="fas fa-rocket"></i> Start Capturing Every Lead Today
                </button>
              </div>
            </div>
            <!-- /Results -->

          </div>
        </div>
        <!-- /Modal Body -->

      </div>
    </div>
  </div>
</div>

@endsection

@section('scripts')
  <script>
  // Add click event to the toggle
        document.getElementById('pricingToggle').addEventListener('change', function() {
            const monthlyPrices = document.querySelectorAll('.price-monthly');
            const annualPrices = document.querySelectorAll('.price-annual');
            
            monthlyPrices.forEach(price => {
          if (this.checked) {
              price.classList.add('d-none');
              price.style.display = 'none';
            } else {
              price.classList.remove('d-none');
              price.style.display = 'flex';
          }
      });
      
      annualPrices.forEach(price => {
          if (this.checked) {
              price.classList.remove('d-none');
              price.style.display = 'flex';
            } else {
              price.classList.add('d-none');
              price.style.display = 'none';
          }
            });
          });

  // ROI Calculator Scripts (safe if modal present)
  let currentField = '';
          let currentValue = '';
          let displayValue = '0';

          // Calculator functionality
          document.addEventListener('DOMContentLoaded', function() {
            // Initialize calculator with defaults
            document.getElementById('missedCalls').value = document.getElementById('missedCalls').value || '';
            document.getElementById('avgCallValue').value = document.getElementById('avgCallValue').value || '200';
            document.getElementById('conversionRate').value = document.getElementById('conversionRate').value || '25';

            // Prefill button labels
            syncFieldButtons();

            // Default active field: missedCalls
            setField('missedCalls');

            // Initialize calculator display
            updateDisplay('ROI Calculator', 'Missed Calls Per Month', '0');
          });

          // Update calculator display
          function updateDisplay(line1, line2, line3) {
            document.getElementById('displayLine1').textContent = line1;
            document.getElementById('displayLine2').textContent = line2;
            document.getElementById('displayLine3').textContent = line3;
          }

          // Set current field for input
          function setField(fieldName) {
            // Save previous value if any
            if (currentField && currentValue) {
              document.getElementById(currentField).value = currentValue;
            }
            
            currentField = fieldName;
            currentValue = document.getElementById(fieldName).value || '';
            
            const fieldLabels = {
              'missedCalls': 'Missed Calls/Month',
              'avgCallValue': 'Avg Call Value ($)',
              'conversionRate': 'Conversion Rate (%)'
            };
            
            updateDisplay('Enter Value', fieldLabels[fieldName], currentValue || '0');
            syncFieldButtons();
          }

          // Input number or decimal
          function inputNumber(num) {
            if (currentField === '') {
              updateDisplay('Select Field First', 'Press Calls, Value, or Rate', '0');
              return;
            }

            if (num === '.' && currentValue.includes('.')) {
              return; // Prevent multiple decimals
            }

            currentValue += num;
            displayValue = currentValue;
            updateDisplay('Enter Value', getFieldLabel(currentField), displayValue);

            // Reflect value into hidden input and button labels live
            document.getElementById(currentField).value = currentValue;
            syncFieldButtons();
          }

          // Get field label
          function getFieldLabel(fieldName) {
            const labels = {
              'missedCalls': 'Missed Calls/Month',
              'avgCallValue': 'Avg Call Value ($)',
              'conversionRate': 'Conversion Rate (%)'
            };
            return labels[fieldName] || '';
          }

          // Clear current input
          function clearCalculator() {
            currentValue = '';
            displayValue = '0';
            updateDisplay('ROI Calculator', 'Missed Calls Per Month', '0');
            // Keep field selection but clear current input display only
          }

          // Backspace
          function backspace() {
            if (currentValue.length > 0) {
              currentValue = currentValue.slice(0, -1);
              displayValue = currentValue || '0';
              updateDisplay('Enter Value', getFieldLabel(currentField), displayValue);
              // Update hidden input and buttons
              document.getElementById(currentField).value = currentValue;
              syncFieldButtons();
            }
          }

          // Reset calculator
          function resetCalculator() {
            currentField = '';
            currentValue = '';
            displayValue = '0';
            document.getElementById('missedCalls').value = '';
            document.getElementById('avgCallValue').value = '200';
            document.getElementById('conversionRate').value = '25';
            document.getElementById('calculationResults').style.display = 'none';
            syncFieldButtons();
            updateDisplay('ROI Calculator', 'Missed Calls Per Month', '0');
            // Re-select default field
            setField('missedCalls');
          }

          // Calculate ROI
          function calculateROI() {
            const missedCalls = parseFloat(document.getElementById('missedCalls').value) || 0;
            const avgCallValue = parseFloat(document.getElementById('avgCallValue').value) || 200;
            const conversionRate = parseFloat(document.getElementById('conversionRate').value) || 25;

            if (missedCalls > 0) {
              // Calculate monthly loss
              const monthlyLoss = missedCalls * avgCallValue * (conversionRate / 100);
              
              // Calculate yearly loss
              const yearlyLoss = monthlyLoss * 12;
              
              // Calculate potential revenue
              const potentialRevenue = yearlyLoss;

              // Update display elements with animated counting
              animateNumber(document.getElementById('monthlyLoss'), monthlyLoss, '$');
              animateNumber(document.getElementById('yearlyLoss'), yearlyLoss, '$');
              animateNumber(document.getElementById('potentialRevenue'), potentialRevenue, '$');

              // Show results
              document.getElementById('calculationResults').style.display = 'block';
              
              // Update display
              updateDisplay('Calculation Complete', `Monthly Loss: $${Math.round(monthlyLoss).toLocaleString()}`, `Yearly Loss: $${Math.round(yearlyLoss).toLocaleString()}`);

              // Scroll to results
              setTimeout(() => {
                document.getElementById('calculationResults').scrollIntoView({ behavior: 'smooth', block: 'center' });
              }, 500);
            } else {
              updateDisplay('Error', 'Please enter missed calls', '0');
            }
          }

          // Show results
          function showResults() {
            const calculationResults = document.getElementById('calculationResults');
            if (calculationResults.style.display === 'none') {
              calculateROI();
            } else {
              calculationResults.style.display = 'none';
              updateDisplay('ROI Calculator', 'Missed Calls Per Month', '0');
            }
          }

          // Sync button labels with current values
          function syncFieldButtons() {
            const calls = document.getElementById('missedCalls').value || '0';
            const value = document.getElementById('avgCallValue').value || '200';
            const rate = document.getElementById('conversionRate').value || '25';

            const btnCallsValue = document.getElementById('btnCallsValue');
            const btnValueAmount = document.getElementById('btnValueAmount');
            const btnRatePercent = document.getElementById('btnRatePercent');

            if (btnCallsValue) btnCallsValue.textContent = calls;
            if (btnValueAmount) btnValueAmount.textContent = value;
            if (btnRatePercent) btnRatePercent.textContent = rate;

            // Toggle active styling
            const btnCalls = document.getElementById('btnCalls');
            const btnValue = document.getElementById('btnValue');
            const btnRate = document.getElementById('btnRate');
            [btnCalls, btnValue, btnRate].forEach(b => b && b.classList.remove('active-field'));
            if (currentField === 'missedCalls' && btnCalls) btnCalls.classList.add('active-field');
            if (currentField === 'avgCallValue' && btnValue) btnValue.classList.add('active-field');
            if (currentField === 'conversionRate' && btnRate) btnRate.classList.add('active-field');
          }

          // Animate number counting effect
          function animateNumber(element, targetValue, prefix = '') {
            const startValue = 0;
            const duration = 1500;
            const startTime = performance.now();

            function updateNumber(currentTime) {
              const elapsed = currentTime - startTime;
              const progress = Math.min(elapsed / duration, 1);
              
              // Easing function for smooth animation
              const easeOutQuart = 1 - Math.pow(1 - progress, 4);
              const currentValue = startValue + (targetValue - startValue) * easeOutQuart;
              
              element.textContent = prefix + Math.round(currentValue).toLocaleString();
              element.classList.add('counting');
              
              if (progress < 1) {
                requestAnimationFrame(updateNumber);
              } else {
                element.classList.remove('counting');
              }
            }

            requestAnimationFrame(updateNumber);
          }

          // Function to scroll to contact section (called from CTA button)
          function scrollToContact() {
            const contactSection = document.querySelector('#contact, .contact-section, .cta-container');
            if (contactSection) {
              contactSection.scrollIntoView({ behavior: 'smooth' });
            } else {
              window.scrollTo({ top: 0, behavior: 'smooth' });
            }
          }

          // Add modal show/hide animations
          document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('roiCalculatorModal');
            
            modal.addEventListener('show.bs.modal', function() {
              // Reset calculator when modal opens
              resetCalculator();
            });

            modal.addEventListener('shown.bs.modal', function() {
              // Initialize calculator display
              updateDisplay('ROI Calculator', 'Missed Calls Per Month', '0');
            });
          });

          // Add keyboard support for calculator
          document.addEventListener('keydown', function(e) {
            const roiModal = document.getElementById('roiCalculatorModal');
            if (roiModal && roiModal.classList.contains('show')) {
              if (e.key >= '0' && e.key <= '9') {
                inputNumber(e.key);
              } else if (e.key === '.') {
                inputNumber('.');
              } else if (e.key === 'Backspace') {
                backspace();
              } else if (e.key === 'Enter' || e.key === '=') {
                calculateROI();
              } else if (e.key === 'Escape') {
                clearCalculator();
              }
            }
          });

          // Stats Counter Animation
          function animateStats() {
            const statCards = document.querySelectorAll('.stat-card');
            
            statCards.forEach(card => {
              const statNumber = card.querySelector('.stat-number');
              const targetValue = parseInt(card.getAttribute('data-stat'));
              
              if (targetValue && statNumber) {
                let currentValue = 0;
                const increment = targetValue / 100; // Adjust speed here
                const duration = 2000; // 2 seconds
                const stepTime = duration / 100;
                
                const timer = setInterval(() => {
                  currentValue += increment;
                  
                  if (currentValue >= targetValue) {
                    currentValue = targetValue;
                    clearInterval(timer);
                  }
                  
                  // Format the number with percentage symbol
                  if (targetValue >= 1000) {
                    statNumber.textContent = Math.floor(currentValue).toLocaleString() + '%';
                  } else {
                    statNumber.textContent = Math.floor(currentValue) + '%';
                  }
                }, stepTime);
              }
            });
          }

          // Intersection Observer for stats animation
          const statsObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
              if (entry.isIntersecting) {
                animateStats();
                statsObserver.unobserve(entry.target);
              }
            });
          }, {
            threshold: 0.5
          });

          // Observe the stats grid
          document.addEventListener('DOMContentLoaded', function() {
            const statsGrid = document.querySelector('.stats-grid');
            if (statsGrid) {
              statsObserver.observe(statsGrid);
            }

            const featureToggles = document.querySelectorAll('[data-feature-toggle]');
            featureToggles.forEach(function(toggle) {
              const button = toggle.querySelector('.feature-more-toggle');
              if (!button) return;

              button.addEventListener('click', function() {
                const isOpen = toggle.classList.toggle('is-open');
                button.textContent = isOpen ? 'See less..' : 'See more..';
                button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
              });
            });

            const contactForm = document.querySelector('.contact-form');
            const contactSubmitBtn = document.getElementById('contactSubmitBtn');
            if (contactForm && contactSubmitBtn) {
              contactForm.addEventListener('submit', function() {
                contactSubmitBtn.disabled = true;
                contactSubmitBtn.querySelector('span').textContent = 'Sending...';
              });
            }

            if (window.location.hash === '#contact' || document.querySelector('.contact-alert-success, .contact-alert-error')) {
              const contactSection = document.getElementById('contact');
              if (contactSection) {
                setTimeout(function() {
                  contactSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 150);
              }
            }
          });
  </script>
@endsection
